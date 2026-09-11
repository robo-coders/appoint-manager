<?php

namespace App\Enums;

enum MessageType: string
{
    case BookingConfirmed = 'booking_confirmed';
    case BookingRequested = 'booking_requested';
    case BookingDeclined = 'booking_declined';
    case Reminder = 'reminder';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
    case SalonNewBooking = 'salon_new_booking';
    case SalonNewRequest = 'salon_new_request';
    case SalonCancellation = 'salon_cancellation';
    case DailyAgenda = 'daily_agenda';
    case WaitlistOffer = 'waitlist_offer';
    case WaitlistGone = 'waitlist_gone';
    case RebookDue = 'rebook_due';

    public function isMarketing(): bool
    {
        return $this === self::RebookDue;
    }
}
