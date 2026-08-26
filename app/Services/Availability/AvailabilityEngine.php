<?php

namespace App\Services\Availability;

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TimeOff;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

final class AvailabilityEngine
{
    /**
     * Every start the salon could actually take, ignoring who is already booked.
     *
     * The public booking page's fallback picker shows unavailable times struck
     * through rather than removing them — an empty grid reads as broken, and a
     * grid with three times in it does not tell a customer whether the salon is
     * busy or shut. That needs two answers: what the day *is*, and what is left
     * of it. This is the first; `slotsFor` is the second, and the difference
     * between them is what gets the strike-through.
     *
     * Minimum notice and the horizon still apply. A struck-through 09:00 at
     * three in the afternoon is noise, not information.
     */
    public function gridFor(
        Tenant $tenant,
        Service $service,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?User $staff = null,
    ): SlotCollection {
        return $this->slotsFor($tenant, $service, $from, $to, $staff, null, ignoreBookings: true);
    }

    /**
     * @param  int|null  $ignoreBookingId  A booking to treat as if it were not there.
     *                                     Used when rescheduling, so a booking does not
     *                                     block the slot it is being moved within.
     * @param  bool  $ignoreBookings  Every existing booking treated as absent. Use
     *                                `gridFor()` rather than passing this by hand.
     */
    public function slotsFor(
        Tenant $tenant,
        Service $service,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?User $staff = null,
        ?int $ignoreBookingId = null,
        bool $ignoreBookings = false,
    ): SlotCollection {
        $from = $from->utc();
        $to = $to->utc();

        if ($to->lte($from) || ! $service->is_active) {
            return SlotCollection::make();
        }

        $staffMembers = $this->staffWhoCanPerform($tenant, $service, $staff);

        if ($staffMembers->isEmpty()) {
            return SlotCollection::make();
        }

        $staffIds = $staffMembers->pluck('id')->all();
        $timezone = new DateTimeZone($tenant->timezone);
        $now = CarbonImmutable::now('UTC');
        $earliest = $now->addHours($tenant->minNoticeHours());
        $horizon = $now->addDays($tenant->horizonDays());

        $loadFrom = $from->subMinutes($service->buffer_minutes + $service->duration_minutes);
        $loadTo = $to->addMinutes($service->buffer_minutes + $service->duration_minutes);

        $rules = AvailabilityRule::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('user_id', $staffIds)
            ->get()
            ->groupBy('user_id');

        $timeOff = TimeOff::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('user_id', $staffIds)
            ->where('starts_at', '<', $loadTo)
            ->where('ends_at', '>', $loadFrom)
            ->get()
            ->groupBy('user_id');

        $bookings = $ignoreBookings
            ? collect()
            : Booking::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereIn('staff_id', $staffIds)
                ->where('status', '!=', BookingStatus::Cancelled->value)
                ->where('starts_at', '<', $loadTo)
                ->where('ends_at', '>', $loadFrom)
                ->when($ignoreBookingId !== null, fn ($query) => $query->whereKeyNot($ignoreBookingId))
                ->get()
                ->groupBy('staff_id');

        $byStart = [];

        foreach ($this->localDays($from, $to, $timezone) as $localDay) {
            $isoWeekday = (int) $localDay->isoWeekday();
            $weekday = Weekday::tryFrom($isoWeekday);

            if ($weekday === null) {
                continue;
            }

            foreach ($staffMembers as $member) {
                $dayRules = ($rules->get($member->id) ?? collect())
                    ->filter(fn (AvailabilityRule $rule) => $rule->weekday === $weekday);

                $windows = [];

                foreach ($dayRules as $rule) {
                    $window = $this->expandRule($localDay, $rule, $timezone);

                    if ($window !== null && ! $window->isEmpty()) {
                        $windows[] = $window;
                    }
                }

                foreach ($timeOff->get($member->id) ?? [] as $block) {
                    $windows = $this->subtract(
                        $windows,
                        CarbonImmutable::parse($block->starts_at)->utc(),
                        CarbonImmutable::parse($block->ends_at)->utc(),
                    );
                }

                foreach ($bookings->get($member->id) ?? [] as $booking) {
                    $windows = $this->subtract(
                        $windows,
                        CarbonImmutable::parse($booking->starts_at)->utc()->subMinutes($service->buffer_minutes),
                        CarbonImmutable::parse($booking->ends_at)->utc()->addMinutes($service->buffer_minutes),
                    );
                }

                foreach ($windows as $window) {
                    foreach ($this->startsIn($window, $service->duration_minutes, $tenant->slotGranularityMinutes(), $timezone) as $start) {
                        if ($start->lt($from) || $start->gte($to)) {
                            continue;
                        }

                        if ($start->lt($earliest) || $start->gt($horizon)) {
                            continue;
                        }

                        $key = (string) $start->getTimestamp();
                        $byStart[$key] ??= ['start' => $start, 'staff' => []];
                        $byStart[$key]['staff'][] = $member->id;
                    }
                }
            }
        }

        ksort($byStart, SORT_NUMERIC);

        $slots = [];

        foreach ($byStart as $row) {
            $slots[] = new Slot($row['start'], array_values(array_unique($row['staff'])));
        }

        return SlotCollection::make($slots);
    }

    /**
     * @return Collection<int, User>
     */
    private function staffWhoCanPerform(Tenant $tenant, Service $service, ?User $staff): Collection
    {
        $query = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('is_bookable', true)
            ->whereHas('services', fn ($services) => $services
                ->withoutGlobalScopes()
                ->where('services.id', $service->id));

        if ($staff !== null) {
            $query->whereKey($staff->id);
        }

        return $query->get();
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function localDays(CarbonImmutable $from, CarbonImmutable $to, DateTimeZone $timezone): array
    {
        $cursor = $from->timezone($timezone)->startOfDay();
        $last = $to->timezone($timezone);

        if ($last->equalTo($last->startOfDay())) {
            $last = $last->subSecond();
        }

        $last = $last->startOfDay();
        $days = [];

        while ($cursor->lte($last)) {
            $days[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    private function expandRule(CarbonImmutable $localDay, AvailabilityRule $rule, DateTimeZone $timezone): ?TimeWindow
    {
        $date = $localDay->timezone($timezone)->toDateString();
        $startTime = substr((string) $rule->start_time, 0, 8);
        $endTime = substr((string) $rule->end_time, 0, 8);

        if (strlen($startTime) === 5) {
            $startTime .= ':00';
        }

        if (strlen($endTime) === 5) {
            $endTime .= ':00';
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$startTime, $timezone);
        $end = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$endTime, $timezone);

        if ($start === false || $end === false) {
            return null;
        }

        return new TimeWindow($start->utc(), $end->utc());
    }

    /**
     * @param  list<TimeWindow>  $windows
     * @return list<TimeWindow>
     */
    private function subtract(array $windows, CarbonImmutable $cutStart, CarbonImmutable $cutEnd): array
    {
        $remaining = [];

        foreach ($windows as $window) {
            if ($cutEnd->lte($window->start) || $cutStart->gte($window->end)) {
                $remaining[] = $window;

                continue;
            }

            if ($cutStart->gt($window->start)) {
                $remaining[] = new TimeWindow($window->start, $cutStart);
            }

            if ($cutEnd->lt($window->end)) {
                $remaining[] = new TimeWindow($cutEnd, $window->end);
            }
        }

        return array_values(array_filter($remaining, fn (TimeWindow $window) => ! $window->isEmpty()));
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function startsIn(TimeWindow $window, int $durationMinutes, int $granularity, DateTimeZone $timezone): array
    {
        $starts = [];
        $cursor = $this->alignUp($window->start, $timezone, $granularity);

        while ($cursor->addMinutes($durationMinutes)->lte($window->end)) {
            $starts[] = $cursor;
            $cursor = $cursor->addMinutes($granularity);
        }

        return $starts;
    }

    private function alignUp(CarbonImmutable $utc, DateTimeZone $timezone, int $granularity): CarbonImmutable
    {
        $local = $utc->timezone($timezone);
        $remainder = ($local->minute * 60 + $local->second) % ($granularity * 60);

        if ($remainder === 0) {
            return $utc;
        }

        return $local->addSeconds(($granularity * 60) - $remainder)->utc();
    }
}
