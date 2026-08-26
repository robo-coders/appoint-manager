<?php

namespace App\Services\Booking;

/**
 * The complete list of things a proposal is allowed to say about itself.
 *
 * This enum is the ranking rule. Adding a case means deciding where it sits in
 * `AppointmentSuggester::PRIMARY_ORDER`, and a slot that matches no case cannot
 * be proposed at all — which is the point. If a candidate cannot justify itself
 * in one of these phrases, it is not the appointment to lead with.
 *
 * The cases are listed in the order the primary proposal prefers them.
 */
enum ReasonKey: string
{
    /** Same weekday as they usually come, at or after their own interval. */
    case UsualDay = 'usual_day';

    /** Their usual time of day, on a different weekday. */
    case UsualTime = 'usual_time';

    /** Due, by their own history, but neither the day nor the time matches. */
    case DueNow = 'due_now';

    /** Their groomer, sooner than they usually come — because nothing later is free. */
    case SoonestWithStaff = 'soonest_with_staff';

    /** Nothing known about them, or their groomer has gone. The next free slot. */
    case FirstAvailable = 'first_available';

    // ---- Alternatives. These never lead; they are the three ways out. ----

    /** Later the same day as the proposal, or the next day. */
    case SameOrNextDay = 'same_or_next_day';

    /** The other half of the day from the proposal. */
    case DifferentTimeOfDay = 'different_time_of_day';

    /** A Saturday or Sunday, offered only by salons that open at weekends. */
    case Weekend = 'weekend';

    /** The last slot before the week runs out. */
    case LastThisWeek = 'last_this_week';
}
