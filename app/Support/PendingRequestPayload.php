<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Tenant;

final class PendingRequestPayload
{
    public static function forTenant(Tenant $tenant): array
    {
        return Booking::query()
            ->with(['customer', 'service', 'staff', 'subject'])
            ->where('status', BookingStatus::Pending->value)
            ->whereNotNull('request_expires_at')
            ->orderBy('starts_at')
            ->get()
            ->map(function (Booking $booking) use ($tenant) {
                $starts = $booking->starts_at->timezone($tenant->timezone);

                return [
                    'id' => $booking->id,
                    'time' => $starts->format('H:i'),
                    'date' => $starts->format('l j F'),
                    'customer' => $booking->customer?->name,
                    'subject' => $booking->subject_id ? $booking->subject?->name : null,
                    'service' => $booking->service?->name,
                    'staff' => $booking->staff?->name,
                    'amount' => $booking->price_at_booking->formatted(),
                    'expires_at' => $booking->request_expires_at?->timezone($tenant->timezone)->format('j M H:i'),
                ];
            })
            ->values()
            ->all();
    }
}
