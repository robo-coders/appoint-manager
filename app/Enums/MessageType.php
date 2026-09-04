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

    /**
     * Is this a message we are sending because we want something, rather than
     * because the customer asked for it?
     *
     * Only the rebooking chase is. A confirmation, a reminder, a cancellation
     * and a waitlist offer are service messages about an appointment the
     * customer made, and a person who replies STOP to a marketing text has not
     * asked to stop being told their dog's appointment moved. Opting out of
     * texts is not opting out of being a customer.
     *
     * This is the distinction the opt-out gate in `Notifier` reads, and the
     * distinction that decides whether "Reply STOP to opt out" is appended.
     */
    public function isMarketing(): bool
    {
        return $this === self::RebookDue;
    }
}
