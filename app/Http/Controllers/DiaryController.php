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

        /*
         * Cancelled bookings are loaded, not filtered out. The filter that used
         * to sit here — `where('status', '!=', 'cancelled')` — made the freed
         * slot invisible on the one screen whose whole job is finding holes in
         * the day. `FreedSlots` decides which cancellations are real gaps and
         * which have already been refilled; see that class for why the filter
         * could not simply be deleted.
         */
        $rows = Booking::query()
            ->with(['staff', 'service', 'customer', 'subject'])
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->orderBy('starts_at')
            ->get();

        $annotations = $freed->annotate($tenant, $rows);

        $bookings = $rows
            // A refilled cancellation is drawn by its replacement, which is
            // already in this list. Two rows for one hour is a lie about the day.
            ->reject(fn (Booking $booking) => ($annotations[$booking->id]['is_refilled'] ?? false))
            ->map(function (Booking $booking) use ($tenant, $annotations) {
                $extra = $annotations[$booking->id] ?? [];

                // The grid works in local wall-clock strings throughout, so the
                // one UTC instant in the annotation is converted here rather
                // than in the browser, where the salon's timezone is not known.
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

        return Inertia::render('Diary/Index', [
            'view' => $view,
            'date' => $focus->toDateString(),
            'range_start' => $from->toDateString(),
            'timezone' => $tenant->timezone,
            'staff' => $staff,
            /*
             * When each person is actually at work, for the focused day.
             *
             * The diary cannot show open time as *space* without knowing where
             * the day begins and ends for each groomer — without it, a column
             * with one appointment in it is indistinguishable from a column for
             * somebody who is not in at all, and every gap runs from 00:00.
             */
            'working' => $view === 'day' ? $this->workingWindows($tenant, $staff, $from) : [],
            'now' => CarbonImmutable::now($tenant->timezone)->format('H:i'),
            'is_today' => $focus->toDateString() === CarbonImmutable::now($tenant->timezone)->toDateString(),
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

    /**
     * Each staff member's working windows on one local day, as `HH:MM` pairs.
     *
     * Availability rules for that weekday, with any time off cut out of them.
     * Deliberately *not* routed through `AvailabilityEngine`: that answers a
     * different question — where a particular service could start, given its
     * duration and buffers — and the diary needs the shape of the day itself,
     * which is a shorter question with a shorter answer.
     *
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
}
