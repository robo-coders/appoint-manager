<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\Booking;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $tz = $tenant->timezone;
        $now = CarbonImmutable::now($tz);
        $weekStart = $now->startOfWeek(CarbonImmutable::MONDAY);
        $lastWeekStart = $weekStart->subWeek();
        $todayStart = $now->startOfDay();

        $thisWeek = $this->bookingsBetween($weekStart->utc(), $weekStart->addWeek()->utc());
        $lastWeek = $this->bookingsBetween($lastWeekStart->utc(), $weekStart->utc());

        $waitlistFilled = Booking::query()
            ->whereNotNull('waitlist_entry_id')
            ->where('starts_at', '>=', $weekStart->utc())
            ->where('starts_at', '<', $weekStart->addWeek()->utc())
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->get();

        $deposits = (int) Booking::query()
            ->where('deposit_status', DepositStatus::Paid->value)
            ->where('deposit_paid_at', '>=', $weekStart->utc())
            ->where('deposit_paid_at', '<', $weekStart->addWeek()->utc())
            ->sum('deposit_at_booking');

        $finished = Booking::query()
            ->where('starts_at', '>=', $weekStart->utc())
            ->where('starts_at', '<', $weekStart->addWeek()->utc())
            ->whereIn('status', [BookingStatus::Completed->value, BookingStatus::NoShow->value])
            ->get();

        $noShows = $finished->where('status', BookingStatus::NoShow)->count();
        $noShowRate = $finished->count() === 0 ? '—' : round(100 * $noShows / $finished->count()).'%';

        $today = Booking::query()
            ->with(['customer', 'service', 'staff'])
            ->where('starts_at', '>=', $todayStart->utc())
            ->where('starts_at', '<', $todayStart->addDay()->utc())
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'time' => $booking->starts_at?->timezone($tz)->format('H:i'),
                'customer' => $booking->customer?->name,
                'service' => $booking->service?->name,
                'staff' => $booking->staff?->name,
            ]);

        $weekChange = $thisWeek - $lastWeek;
        $waitlistRevenue = $waitlistFilled->sum(fn (Booking $booking) => $booking->price_at_booking->amount);

        return Inertia::render('Dashboard', [
            'stats' => [
                [
                    'key' => 'bookings',
                    'label' => 'Bookings this week',
                    'value' => (string) $thisWeek,
                    'hint' => $weekChange === 0 ? 'Same as last week' : (($weekChange > 0 ? '+' : '').$weekChange.' vs last week'),
                ],
                [
                    'key' => 'waitlist',
                    'label' => 'Filled from the waitlist',
                    'value' => (string) $waitlistFilled->count(),
                    'hint' => (new Money($waitlistRevenue, $tenant->currency))->formatted().' of revenue',
                    'highlight' => true,
                ],
                [
                    'key' => 'deposits',
                    'label' => 'Deposits taken',
                    'value' => (new Money($deposits, $tenant->currency))->formatted(),
                    'hint' => 'This week',
                ],
                [
                    'key' => 'noshow',
                    'label' => 'No-show rate',
                    'value' => $noShowRate,
                    'hint' => 'Completed and no-shows this week',
                ],
            ],
            'today' => $today,
        ]);
    }

    private function bookingsBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Booking::query()
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->count();
    }
}
