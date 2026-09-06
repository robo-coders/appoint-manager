<?php

namespace App\Sandbox;

use App\Enums\BookingStatus;
use App\Enums\MessageChannel;
use App\Enums\SlotOfferStatus;
use App\Models\Booking;
use App\Models\Message;
use App\Models\SlotOffer;
use App\Models\Tenant;

final class SandboxSummary
{
    /**
     * @return array{no_shows: int, pending_offers: int, expired_holds: int, outbox: int, last_action: array{label: string, at: string}|null}
     */
    public static function for(Tenant $tenant): array
    {
        $cutoff = now()->subMinutes((int) config('booking.pending_hold_minutes'));
        $last = SandboxState::get($tenant)['last_action'] ?? null;

        return [
            'no_shows' => Booking::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', BookingStatus::NoShow->value)
                ->count(),
            'pending_offers' => SlotOffer::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', SlotOfferStatus::Sent->value)
                ->count(),
            'expired_holds' => Booking::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', BookingStatus::Pending->value)
                ->whereNull('request_expires_at')
                ->where('created_at', '<=', $cutoff)
                ->count(),
            'outbox' => Message::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('channel', MessageChannel::Sms->value)
                ->count(),
            'last_action' => is_array($last) && isset($last['label'], $last['at'])
                ? ['label' => (string) $last['label'], 'at' => (string) $last['at']]
                : null,
        ];
    }
}
