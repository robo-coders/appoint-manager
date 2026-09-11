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

    /** @return list<self> */
    public static function vacating(): array
    {
        return [self::Cancelled, self::Declined, self::NoShow];
    }

    /** @return list<string> */
    public static function vacatingValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::vacating());
    }
}
