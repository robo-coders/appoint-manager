<?php

namespace App\Enums;

enum MessageType: string
{
    case BookingConfirmed = 'booking_confirmed';
    case Reminder = 'reminder';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
    case SalonNewBooking = 'salon_new_booking';
    case SalonCancellation = 'salon_cancellation';
    case DailyAgenda = 'daily_agenda';
    case WaitlistOffer = 'waitlist_offer';
    case WaitlistGone = 'waitlist_gone';
    case RebookDue = 'rebook_due';
}
