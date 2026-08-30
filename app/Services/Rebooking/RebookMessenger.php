<?php

namespace App\Services\Rebooking;

use App\Enums\MessageType;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Tenant;
use App\Services\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Automatic rebooking messages. Off until the operator confirms a dry run.
 */
final class RebookMessenger
{
    public function __construct(
        private OverdueSubjects $overdue,
        private Notifier $notifier,
    ) {}

    public function isEnabled(Tenant $tenant): bool
    {
        return (bool) data_get($tenant->settings, 'rebooking.messages_enabled', false);
    }

    /**
     * @return array{count: int, messages: list<array<string, mixed>>}
     */
    public function dryRun(Tenant $tenant, ?CarbonImmutable $today = null): array
    {
        $rows = $this->overdue->forTenant($tenant, $today);

        $messages = $rows->map(fn (array $row) => [
            'subject_id' => $row['subject_id'],
            'subject_name' => $row['subject_name'],
            'customer_name' => $row['customer_name'],
            'phone' => $row['phone'],
            'due_label' => $row['due_label'],
            'body' => $this->body($tenant, $row),
        ])->all();

        return [
            'count' => count($messages),
            'messages' => $messages,
        ];
    }

    public function enableAfterDryRun(Tenant $tenant): void
    {
        $settings = $tenant->settings ?? [];
        $settings['rebooking']['messages_enabled'] = true;
        $settings['rebooking']['messages_confirmed_at'] = now()->toIso8601String();
        $tenant->forceFill(['settings' => $settings])->save();
    }

    public function disable(Tenant $tenant): void
    {
        $settings = $tenant->settings ?? [];
        $settings['rebooking']['messages_enabled'] = false;
        $tenant->forceFill(['settings' => $settings])->save();
    }

    /**
     * Send to everyone currently overdue. No-op when sending is off.
     *
     * @return int Messages queued
     */
    public function sendDue(Tenant $tenant, ?CarbonImmutable $today = null): int
    {
        if (! $this->isEnabled($tenant)) {
            return 0;
        }

        $sent = 0;

        foreach ($this->dryRun($tenant, $today)['messages'] as $row) {
            $subject = Subject::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($row['subject_id'])->first();
            $customer = $subject?->customer_id
                ? Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($subject->customer_id)->first()
                : null;

            if ($customer === null || $this->alreadySentThisCycle($tenant, $customer->id)) {
                continue;
            }

            $this->notifier->rebookDue($tenant, $customer, $subject, (string) $row['body']);
            $sent++;
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function body(Tenant $tenant, array $row): string
    {
        $url = book_url($tenant->slug);

        return Str::limit(
            $tenant->name.': '.$row['subject_name'].' is due '.$row['due_label'].'. Book: '.$url,
            160,
            '',
        );
    }

    private function alreadySentThisCycle(Tenant $tenant, int $customerId): bool
    {
        return Message::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->where('type', MessageType::RebookDue->value)
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(14))
            ->exists();
    }
}
