<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Declined = 'declined';
    case Completed = 'completed';
    case NoShow = 'no_show';

    /**
     * The statuses that leave the hour empty.
     *
     * One list, because there are two readers of it and they must never
     * disagree: `Booking::occupiesTime()`, which every screen asks, and
     * `AvailabilityEngine`, which decides what can be booked. When those two
     * drifted apart the diary drew a slot as free that the engine still
     * refused to sell, and the waitlist texted people about an hour they could
     * not claim.
     *
     * `NoShow` belongs here for the same reason `Cancelled` does. The customer
     * is not coming, the chair is empty, and the only thing separating the two
     * is whose fault it was — which is a question for the no-show rate, not for
     * the availability engine. It is *not* a general "this booking does not
     * count" list: a no-show still happened, still appears in the diary as
     * history, and is still counted by every stat that measures finished
     * appointments.
     *
     * @return list<self>
     */
    public static function vacating(): array
    {
        return [self::Cancelled, self::Declined, self::NoShow];
    }

    /**
     * `vacating()` as the raw column values, for query builders.
     *
     * @return list<string>
     */
    public static function vacatingValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::vacating());
    }
}
