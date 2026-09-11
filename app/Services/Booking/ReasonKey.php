<?php

namespace App\Services\Booking;

enum ReasonKey: string
{
    case UsualDay = 'usual_day';

    case UsualTime = 'usual_time';

    case DueNow = 'due_now';

    case SoonestWithStaff = 'soonest_with_staff';

    case FirstAvailable = 'first_available';

    case SameOrNextDay = 'same_or_next_day';

    case DifferentTimeOfDay = 'different_time_of_day';

    case Weekend = 'weekend';

    case LastThisWeek = 'last_this_week';
}
