<?php

namespace App\Http\Controllers;

use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\Booking\FreedSlots;
use App\Support\BookingPayload;
use App\Support\PendingRequestPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DiaryController extends Controller
{
    public function __invoke(Request $request, FreedSlots $freed): Response
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

        $rows = Booking::query()
            ->with(['staff', 'service', 'customer', 'subject'])
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->orderBy('starts_at')
            ->get();

        $annotations = $freed->annotate($tenant, $rows);

        $bookings = $rows
            ->reject(fn (Booking $booking) => ($annotations[$booking->id]['is_refilled'] ?? false))
            ->map(function (Booking $booking) use ($tenant, $annotations) {
                $extra = $annotations[$booking->id] ?? [];

                if (! empty($extra['gap_starts_at'])) {
                    $extra['gap_starts_at'] = CarbonImmutable::parse($extra['gap_starts_at'])
                        ->timezone($tenant->timezone)
                        ->format('Y-m-d H:i');
                }

                return BookingPayload::toArray($booking, $tenant->timezone, $extra);
            })
            ->values();

        $staff = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'colour', 'is_bookable'])
            ->values();

        $working = $view === 'day' ? $this->workingWindows($tenant, $staff, $from) : [];
        $closed = $view === 'day'
            && $staff->isNotEmpty()
            && collect($working)->every(fn (array $windows) => $windows === []);

        return Inertia::render('Diary/Index', [
            'view' => $view,
            'date' => $focus->toDateString(),
            'range_start' => $from->toDateString(),
            'timezone' => $tenant->timezone,
            'staff' => $staff,
            'working' => $working,
            'closed' => $closed,
            'next_open' => $closed ? $this->nextOpenDay($staff, $from) : null,
            'now' => CarbonImmutable::now($tenant->timezone)->format('H:i'),
            'is_today' => $focus->toDateString() === CarbonImmutable::now($tenant->timezone)->toDateString(),
            'services' => Service::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'duration_minutes', 'price', 'deposit_amount', 'suggested_interval_days'])
                ->map(fn (Service $service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'duration_minutes' => $service->duration_minutes,
                    'price' => $service->price->toArray(),
                    'suggested_interval_days' => $service->suggested_interval_days,
                ])
                ->values(),
            'bookings' => $bookings,
            'pending_requests' => PendingRequestPayload::forTenant($tenant),
        ]);
    }

    /**
     * @param  Collection<int, User>  $staff
     * @return array<int, list<array{start: string, end: string}>>
     */
    private function workingWindows(mixed $tenant, $staff, CarbonImmutable $dayStart): array
    {
        $tz = $tenant->timezone;
        $ids = $staff->pluck('id')->all();
        $weekday = Weekday::tryFrom((int) $dayStart->isoWeekday());

        if ($weekday === null || $ids === []) {
            return [];
        }

        $rules = AvailabilityRule::query()
            ->whereIn('user_id', $ids)
            ->where('weekday', $weekday->value)
            ->get()
            ->groupBy('user_id');

        $off = TimeOff::query()
            ->whereIn('user_id', $ids)
            ->where('starts_at', '<', $dayStart->addDay()->utc())
            ->where('ends_at', '>', $dayStart->utc())
            ->get()
            ->groupBy('user_id');

        $out = [];

        foreach ($staff as $member) {
            $windows = [];

            foreach ($rules->get($member->id) ?? [] as $rule) {
                $windows[] = [
                    'start' => $dayStart->setTimeFromTimeString(substr((string) $rule->start_time, 0, 8)),
                    'end' => $dayStart->setTimeFromTimeString(substr((string) $rule->end_time, 0, 8)),
                ];
            }

            foreach ($off->get($member->id) ?? [] as $block) {
                $cutFrom = CarbonImmutable::parse($block->starts_at)->timezone($tz);
                $cutTo = CarbonImmutable::parse($block->ends_at)->timezone($tz);
                $next = [];

                foreach ($windows as $window) {
                    if ($cutTo->lte($window['start']) || $cutFrom->gte($window['end'])) {
                        $next[] = $window;

                        continue;
                    }

                    if ($cutFrom->gt($window['start'])) {
                        $next[] = ['start' => $window['start'], 'end' => $cutFrom];
                    }

                    if ($cutTo->lt($window['end'])) {
                        $next[] = ['start' => $cutTo, 'end' => $window['end']];
                    }
                }

                $windows = $next;
            }

            $out[$member->id] = array_values(array_map(
                fn (array $window) => [
                    'start' => $window['start']->format('H:i'),
                    'end' => $window['end']->format('H:i'),
                ],
                $windows,
            ));
        }

        return $out;
    }

    /** @param  Collection<int, User>  $staff */
    private function nextOpenDay(Collection $staff, CarbonImmutable $from): ?string
    {
        $ids = $staff->pluck('id');

        if ($ids->isEmpty()) {
            return null;
        }

        $weekdays = AvailabilityRule::query()
            ->whereIn('user_id', $ids)
            ->pluck('weekday')
            ->map(fn ($weekday) => (int) ($weekday instanceof Weekday ? $weekday->value : $weekday))
            ->unique();

        if ($weekdays->isEmpty()) {
            return null;
        }

        for ($i = 1; $i <= 14; $i++) {
            $day = $from->addDays($i);

            if ($weekdays->contains((int) $day->isoWeekday())) {
                return $day->toDateString();
            }
        }

        return null;
    }
}
