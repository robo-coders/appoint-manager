<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use Illuminate\Console\Command;

class ReleaseExpiredPendingBookings extends Command
{
    protected $signature = 'bookings:release-expired';

    protected $description = 'Cancel pending bookings whose checkout hold has expired';

    public function handle(BookingService $bookings): int
    {
        $cutoff = now()->subMinutes((int) config('booking.pending_hold_minutes'));

        Booking::withoutGlobalScopes()
            ->where('status', BookingStatus::Pending->value)
            ->whereNull('request_expires_at')
            ->where('created_at', '<=', $cutoff)
            ->get()
            ->each(fn (Booking $booking) => $bookings->cancel($booking, 'checkout_expired'));

        return self::SUCCESS;
    }
}
