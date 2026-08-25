<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBookingReminder implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $bookingId) {}

    public function handle(Notifier $notifier): void
    {
        $booking = Booking::withoutGlobalScopes()->with(['tenant', 'customer', 'service'])->find($this->bookingId);

        if ($booking === null) {
            return;
        }

        if ($booking->status !== BookingStatus::Confirmed) {
            return;
        }

        if ($booking->reminder_cancelled_at !== null) {
            return;
        }

        $notifier->reminder($booking);
    }
}
