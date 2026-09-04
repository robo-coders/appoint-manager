<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use Illuminate\Console\Command;

class ExpireBookingRequests extends Command
{
    protected $signature = 'bookings:expire-requests';

    protected $description = 'Decline pending booking requests whose approval window has expired';

    public function handle(BookingService $bookings): int
    {
        Booking::withoutGlobalScopes()
            ->where('status', BookingStatus::Pending->value)
            ->whereNotNull('request_expires_at')
            ->where('request_expires_at', '<=', now())
            ->get()
            ->each(fn (Booking $booking) => $bookings->decline($booking, null, null, 'booking.request.expired'));

        return self::SUCCESS;
    }
}
