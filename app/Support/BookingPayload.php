<?php

namespace App\Support;

use App\Models\Booking;

class BookingPayload
{
    /**
     * @param  array<string, mixed>  $extra  Screen-specific keys merged over the base
     *                                       payload — the diary's freed-slot
     *                                       annotation, for one. Kept out of the base
     *                                       so every caller does not pay for a
     *                                       waitlist query it will not read.
     * @return array<string, mixed>
     */
    public static function toArray(Booking $booking, string $timezone, array $extra = []): array
    {
        $booking->loadMissing(['staff', 'service', 'customer', 'subject']);

        return array_merge([
            'id' => $booking->id,
            'staff_id' => $booking->staff_id,
            'staff_name' => $booking->staff?->name,
            'staff_colour' => $booking->staff?->colour,
            'service_id' => $booking->service_id,
            'service_name' => $booking->service?->name,
            'customer_id' => $booking->customer_id,
            'customer_name' => $booking->customer?->name,
            'subject_id' => $booking->subject_id,
            'subject_name' => $booking->subject?->name,
            'starts_at' => $booking->starts_at?->utc()->toIso8601String(),
            'ends_at' => $booking->ends_at?->utc()->toIso8601String(),
            'starts_at_local' => $booking->starts_at?->timezone($timezone)->format('Y-m-d H:i'),
            'ends_at_local' => $booking->ends_at?->timezone($timezone)->format('Y-m-d H:i'),
            'status' => $booking->status->value,
            'deposit_status' => $booking->deposit_status->value,
            'price_at_booking' => $booking->price_at_booking->toArray(),
            'deposit_at_booking' => $booking->deposit_at_booking->toArray(),
            'source' => $booking->source->value,
            'public_token' => $booking->public_token,
            'cancellation_reason' => $booking->cancellation_reason,
            'duration_minutes' => $booking->service?->duration_minutes,
        ], $extra);
    }
}
