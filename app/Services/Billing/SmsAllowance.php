<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Notifications\SmsAllowanceWarning;
use App\Support\BillingPrice;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * How many SMS a tenant may still send, and what happens when they cannot.
 *
 * Included allowance resets each billing cycle. Prepaid (top-ups and grants)
 * rolls over. The hard ceiling is a cycle total and cannot be raised by a
 * top-up — only by super admin.
 */
final class SmsAllowance
{
    public function included(Tenant $tenant): int
    {
        return $tenant->sms_included_override ?? (int) config('billing.sms_included');
    }

    public function ceiling(Tenant $tenant): int
    {
        return $tenant->sms_ceiling_override ?? (int) config('billing.sms_hard_ceiling');
    }

    public function maybeResetCycle(Tenant $tenant): void
    {
        $started = $tenant->sms_cycle_started_at;

        if ($started === null) {
            $tenant->forceFill(['sms_cycle_started_at' => now()])->save();

            return;
        }

        if ($started->copy()->addMonth()->isFuture()) {
            return;
        }

        $this->resetCycle($tenant);
    }

    public function resetCycle(Tenant $tenant): void
    {
        $tenant->forceFill([
            'sms_cycle_used' => 0,
            'sms_cycle_started_at' => now(),
            'sms_warnings_sent' => [],
        ])->save();
    }

    public function canSend(Tenant $tenant): bool
    {
        $this->maybeResetCycle($tenant);
        $tenant->refresh();

        return $this->blockReason($tenant) === null;
    }

    /**
     * Why SMS will not go out. Null means it will.
     *
     * @return 'killed'|'ceiling'|'allowance'|null
     */
    public function blockReason(Tenant $tenant): ?string
    {
        if ($tenant->sms_killed_at !== null) {
            return 'killed';
        }

        if ((int) $tenant->sms_cycle_used >= $this->ceiling($tenant)) {
            return 'ceiling';
        }

        $remainingIncluded = max(0, $this->included($tenant) - (int) $tenant->sms_cycle_used);

        if ($remainingIncluded > 0 || (int) $tenant->sms_prepaid > 0) {
            return null;
        }

        return 'allowance';
    }

    /**
     * Count one successful send against this tenant. Call only after the
     * provider accepted the message.
     */
    public function consume(Tenant $tenant): void
    {
        $this->maybeResetCycle($tenant);
        $tenant->refresh();

        $included = $this->included($tenant);
        $used = (int) $tenant->sms_cycle_used + 1;
        $prepaid = (int) $tenant->sms_prepaid;

        if ($used > $included) {
            $prepaid = max(0, $prepaid - 1);
        }

        $tenant->forceFill([
            'sms_cycle_used' => $used,
            'sms_prepaid' => $prepaid,
        ])->save();

        $this->maybeWarn($tenant->fresh());
    }

    public function applyTopUp(Tenant $tenant): void
    {
        $size = (int) config('billing.sms_topup_size');
        $tenant->forceFill([
            'sms_prepaid' => (int) $tenant->sms_prepaid + $size,
        ])->save();
    }

    public function grant(Tenant $tenant, int $credits): void
    {
        $tenant->forceFill([
            'sms_prepaid' => (int) $tenant->sms_prepaid + max(0, $credits),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Tenant $tenant): array
    {
        $this->maybeResetCycle($tenant);
        $tenant->refresh();

        $included = $this->included($tenant);
        $used = (int) $tenant->sms_cycle_used;
        $stopped = $this->blockReason($tenant);
        $percent = $included > 0 ? (int) floor(($used / $included) * 100) : 0;
        $thresholds = array_map('intval', (array) config('billing.sms_warning_thresholds', [80, 100]));
        rsort($thresholds);
        $warning = null;

        foreach ($thresholds as $threshold) {
            if ($percent >= $threshold) {
                $warning = $threshold;
                break;
            }
        }

        $remainingIncluded = max(0, $included - $used);

        return [
            'used' => $used,
            'included' => $included,
            'prepaid' => (int) $tenant->sms_prepaid,
            'ceiling' => $this->ceiling($tenant),
            'remaining' => $remainingIncluded + (int) $tenant->sms_prepaid,
            'percent' => $percent,
            'can_send' => $stopped === null,
            'stopped' => $stopped,
            'warning' => $warning,
            'killed' => $tenant->sms_killed_at !== null,
            'topup_price' => BillingPrice::formatPence(BillingPrice::topUpPence()),
            'topup_size' => (int) config('billing.sms_topup_size'),
        ];
    }

    private function maybeWarn(Tenant $tenant): void
    {
        $included = $this->included($tenant);
        $used = (int) $tenant->sms_cycle_used;
        $percent = $included > 0 ? (int) floor(($used / $included) * 100) : 0;
        $sent = array_map('strval', $tenant->sms_warnings_sent ?? []);
        $thresholds = array_map('intval', (array) config('billing.sms_warning_thresholds', [80, 100]));

        foreach ($thresholds as $threshold) {
            $key = (string) $threshold;

            if ($percent < $threshold || in_array($key, $sent, true)) {
                continue;
            }

            $this->notifyTenant($tenant, $threshold);
            $sent[] = $key;
        }

        if ((int) $tenant->sms_cycle_used >= $this->ceiling($tenant) && ! in_array('ceiling', $sent, true)) {
            $this->notifyTenant($tenant, 100);
            $this->alertOwner($tenant);
            $sent[] = 'ceiling';
        }

        $tenant->forceFill(['sms_warnings_sent' => array_values(array_unique($sent))])->save();
    }

    private function notifyTenant(Tenant $tenant, int $threshold): void
    {
        $owners = $tenant->users()->where('role', 'owner')->get();

        if ($owners->isEmpty()) {
            return;
        }

        Notification::send($owners, new SmsAllowanceWarning($tenant, $threshold, $this->snapshot($tenant)));
    }

    private function alertOwner(Tenant $tenant): void
    {
        $to = (string) config('billing.owner_alert_email');

        if ($to === '') {
            return;
        }

        Mail::raw(
            $tenant->name.' ('.$tenant->slug.') has hit the SMS hard ceiling of '.$this->ceiling($tenant)
                .' this cycle. Sending is stopped. Top-ups will not lift it.',
            function ($message) use ($to, $tenant): void {
                $message->to($to)->subject('SMS ceiling: '.$tenant->name);
            }
        );
    }
}
