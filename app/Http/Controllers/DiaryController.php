<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use App\Support\BookingPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DiaryController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);

        $tenant = current_tenant();
        abort_unless($tenant !== null, 403);

        $view = $request->string('view')->toString() === 'week' ? 'week' : 'day';
        $date = $request->string('date')->toString();
        $focus = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? CarbonImmutable::parse($date, $tenant->timezone)
            : CarbonImmutable::now($tenant->timezone);

        $from = $view === 'week' ? $focus->startOfWeek(CarbonImmutable::MONDAY) : $focus->startOfDay();
        $to = $view === 'week' ? $from->addWeek() : $from->addDay();

        $bookings = Booking::query()
            ->with(['staff', 'service', 'customer', 'subject'])
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $booking) => BookingPayload::toArray($booking, $tenant->timezone))
            ->values();

        return Inertia::render('Diary/Index', [
            'view' => $view,
            'date' => $focus->toDateString(),
            'range_start' => $from->toDateString(),
            'timezone' => $tenant->timezone,
            'staff' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'colour', 'is_bookable'])
                ->values(),
            'services' => Service::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'duration_minutes', 'price', 'deposit_amount'])
                ->map(fn (Service $service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'duration_minutes' => $service->duration_minutes,
                    'price' => $service->price->toArray(),
                ])
                ->values(),
            'bookings' => $bookings,
        ]);
    }
}
