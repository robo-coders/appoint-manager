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
 *
 * **The unit is a segment.** Everything here counts what the carrier bills,
 * not how many times we pressed send. A 200-message allowance spent on
 * two-segment messages is 100 messages, and pretending otherwise means we
 * absorb the difference silently. `consume()` takes a segment count and
 * `canSend()` takes the segment count of the message about to go out, so a
 * two-segment message cannot slip past a one-segment remainder.
 */
final class SmsAllowance
{
    public function included(Tenant $tenant): int
    {
        if ($tenant->sms_included_override !== null) {
            return (int) $tenant->sms_included_override;
        }

        if ($tenant->onTrial()) {
            $trial = config('billing.sms_trial_included');

            if ($trial !== null) {
                return (int) $trial;
            }
        }

        return (int) config('billing.sms_included');
    }

    public function ceiling(Tenant $tenant): int
    {
        return $tenant->sms_ceiling_override ?? (int) config('billing.sms_hard_ceiling');
    }

    /**
     * Roll the cycle over when it is a month old and nothing external has done
     * it for us.
     *
     * A paying tenant's cycle is reset by the invoice webhook. A tenant with no
     * invoice yet — anybody on trial — has no such event, so the month is read
     * off `sms_cycle_started_at`. That is what gives a sixty-day trial two
     * included packs, and it is deliberate policy now rather than a side effect:
     * `billing.sms_trial_resets_monthly`.
     */
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

        if ($tenant->onTrial() && ! (bool) config('billing.sms_trial_resets_monthly', true)) {
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

    /**
     * @param  int  $segments  What the message about to go out will be billed as.
     */
    public function canSend(Tenant $tenant, int $segments = 1): bool
    {
        $this->maybeResetCycle($tenant);
        $tenant->refresh();

        return $this->blockReason($tenant, $segments) === null;
    }

    /**
     * Why SMS will not go out. Null means it will.
     *
     * @param  int  $segments  Cost of the message being considered, so a
     *                         two-segment message cannot be waved through on a
     *                         one-segment remainder.
     * @return 'killed'|'ceiling'|'allowance'|null
     */
    public function blockReason(Tenant $tenant, int $segments = 1): ?string
    {
        $segments = max(1, $segments);

        if ($tenant->sms_killed_at !== null) {
            return 'killed';
        }

        if ((int) $tenant->sms_cycle_used + $segments > $this->ceiling($tenant)) {
            return 'ceiling';
        }

        $remainingIncluded = max(0, $this->included($tenant) - (int) $tenant->sms_cycle_used);

        if ($remainingIncluded + (int) $tenant->sms_prepaid >= $segments) {
            return null;
        }

        return 'allowance';
    }

    /**
     * Count a successful send against this tenant, in segments. Call only after
     * the provider accepted the message.
     *
     * A rejected message costs nothing and must not appear here — see
     * `SendSms::handle()`, which consumes after the gateway returns a SID.
     */
    public function consume(Tenant $tenant, int $segments = 1): void
    {
        $segments = max(1, $segments);

        $this->maybeResetCycle($tenant);
        $tenant->refresh();

        $included = $this->included($tenant);
        $before = (int) $tenant->sms_cycle_used;
        $used = $before + $segments;
        $prepaid = (int) $tenant->sms_prepaid;

        // Only the part of this send that lands beyond the included pack comes
        // out of prepaid inventory. A message that straddles the boundary is
        // split, which is why this is arithmetic rather than a flag.
        $beyondIncluded = max(0, $used - max($included, $before));

        if ($beyondIncluded > 0) {
            $prepaid = max(0, $prepaid - $beyondIncluded);
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
