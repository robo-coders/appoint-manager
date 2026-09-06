<?php

namespace App\Sandbox;

use App\Enums\MessageChannel;
use App\Enums\MessageType;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;

final class SmsOutbox
{
    /**
     * @return list<array{id: int, at: string, recipient: string, badge: string, body: string}>
     */
    public static function list(Tenant $tenant): array
    {
        $rows = Message::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('channel', MessageChannel::Sms->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $names = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $rows->pluck('customer_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return $rows->map(function (Message $message) use ($names): array {
            $type = $message->type instanceof MessageType ? $message->type : MessageType::from((string) $message->type);

            return [
                'id' => (int) $message->id,
                'at' => $message->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'recipient' => (string) ($names[$message->customer_id] ?? 'Unknown customer'),
                'badge' => self::badge($type, (string) $message->body),
                'body' => (string) $message->body,
            ];
        })->all();
    }

    public static function clear(Tenant $tenant): int
    {
        return Message::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('channel', MessageChannel::Sms->value)
            ->delete();
    }

    private static function badge(MessageType $type, string $body): string
    {
        return match ($type) {
            MessageType::Reminder => 'Reminder',
            MessageType::WaitlistOffer, MessageType::WaitlistGone => 'Waitlist offer',
            MessageType::Cancelled => str_contains(strtolower($body), 'no-show') || str_contains(strtolower($body), 'no show')
                ? 'No-show'
                : 'Cancelled',
            MessageType::BookingConfirmed => str_contains(strtolower($body), 'stamp') || str_contains(strtolower($body), 'loyalty')
                ? 'Loyalty'
                : 'Confirmed',
            default => match ($type) {
                MessageType::Rescheduled => 'Rescheduled',
                MessageType::BookingDeclined => 'Declined',
                MessageType::RebookDue => 'Rebook',
                default => 'Message',
            },
        };
    }
}
