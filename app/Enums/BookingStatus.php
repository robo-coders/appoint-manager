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
}
