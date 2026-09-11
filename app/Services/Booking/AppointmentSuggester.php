<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\SetupReason;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Availability\AvailabilityEngine;
use App\Services\Availability\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AppointmentSuggester
{
    private const LOOKAHEAD_DAYS = 42;

    private const HISTORY_DEPTH = 3;

    private const MIN_CREDIBLE_INTERVAL_DAYS = 7;

    private const USUAL_TIME_TOLERANCE_MINUTES = 60;

    public function __construct(private AvailabilityEngine $engine) {}

    public function suggest(
        Tenant $tenant,
        ?Customer $customer = null,
        ?Service $service = null,
        ?User $staff = null,
        ?CarbonImmutable $now = null,
    ): Suggestion {
        $now = ($now ?? CarbonImmutable::now())->utc();

        $reason = BookingReadiness::reasonFor($tenant);

        if ($reason !== null) {
            return new Suggestion(null, [], false, $customer, null, null, null, setupReason: $reason);
        }

        $history = $customer === null ? collect() : $this->history($tenant, $customer, $now);
        $returning = $history->isNotEmpty();

        $service = $service ?? $this->lastOf($history, 'service') ?? $this->defaultService($tenant);

        if ($service === null) {
            return new Suggestion(null, [], $returning, $customer, null, null, null);
        }

        $subject = $returning ? $this->lastSubject($history) : null;
        $usualStaff = $staff ?? ($returning ? $this->lastOf($history, 'staff') : null);
        $intervalDays = $returning ? $this->typicalIntervalDays($history) : null;

        if (! BookingReadiness::hasStaffForService($tenant, $service)) {
            return new Suggestion(
                null,
                [],
                $returning,
                $customer,
                $service,
                $subject,
                $intervalDays,
                setupReason: SetupReason::NoStaffForService,
            );
        }

        $slots = $this->slots($tenant, $service, $now);

        if ($slots->isEmpty()) {
            return new Suggestion(null, [], $returning, $customer, $service, $subject, $intervalDays);
        }

        $primary = $this->primary($tenant, $service, $subject, $slots, $usualStaff, $history, $intervalDays, $now);

        if ($primary === null) {
            return new Suggestion(null, [], $returning, $customer, $service, $subject, $intervalDays);
        }

        return new Suggestion(
            $primary,
            $this->alternatives($tenant, $service, $subject, $slots, $primary),
            $returning,
            $customer,
            $service,
            $subject,
            $intervalDays,
        );
    }

    /**
     * @param  Collection<int, array{slot: Slot, staff: User|null}>  $slots
     * @param  Collection<int, Booking>  $history
     */
    private function primary(
        Tenant $tenant,
        Service $service,
        ?Subject $subject,
        Collection $slots,
        ?User $usualStaff,
        Collection $history,
        ?int $intervalDays,
        CarbonImmutable $now,
    ): ?Proposal {
        if ($history->isEmpty() || $usualStaff === null || ! $this->stillWorking($slots, $usualStaff)) {
            return $this->propose($tenant, $service, $subject, $slots->first(), null, ReasonKey::FirstAvailable);
        }

        $withStaff = $slots->filter(fn (array $entry) => in_array($usualStaff->id, $entry['slot']->staffIds, true))->values();

        if ($withStaff->isEmpty()) {
            return $this->propose($tenant, $service, $subject, $slots->first(), null, ReasonKey::FirstAvailable);
        }

        $due = $now->addDays($intervalDays ?? $service->suggestedIntervalDays());
        $atOrAfterDue = $withStaff->filter(fn (array $entry) => $entry['slot']->startsAt->gte($due))->values();

        if ($atOrAfterDue->isEmpty()) {
            return $this->propose($tenant, $service, $subject, $withStaff->first(), $usualStaff, ReasonKey::SoonestWithStaff);
        }

        $usualWeekday = $this->usualWeekday($history, $tenant->timezone);
        $usualMinute = $this->usualMinuteOfDay($history, $tenant->timezone);

        if ($usualWeekday !== null) {
            $onUsualDay = $atOrAfterDue->first(
                fn (array $entry) => (int) $entry['slot']->startsAt->timezone($tenant->timezone)->isoWeekday() === $usualWeekday
            );

            if ($onUsualDay !== null) {
                return $this->propose($tenant, $service, $subject, $onUsualDay, $usualStaff, ReasonKey::UsualDay);
            }
        }

        if ($usualMinute !== null) {
            $atUsualTime = $atOrAfterDue->first(function (array $entry) use ($tenant, $usualMinute) {
                $local = $entry['slot']->startsAt->timezone($tenant->timezone);

                return abs(($local->hour * 60 + $local->minute) - $usualMinute) <= self::USUAL_TIME_TOLERANCE_MINUTES;
            });

            if ($atUsualTime !== null) {
                return $this->propose($tenant, $service, $subject, $atUsualTime, $usualStaff, ReasonKey::UsualTime);
            }
        }

        return $this->propose($tenant, $service, $subject, $atOrAfterDue->first(), $usualStaff, ReasonKey::DueNow);
    }

    /**
     * @param  Collection<int, array{slot: Slot, staff: User|null}>  $slots
     * @return list<Proposal>
     */
    private function alternatives(
        Tenant $tenant,
        Service $service,
        ?Subject $subject,
        Collection $slots,
        Proposal $primary,
    ): array {
        $tz = $tenant->timezone;
        $anchor = $primary->startsAt->timezone($tz);

        $slots = $slots->filter(fn (array $e) => $e['slot']->startsAt->gte($primary->startsAt))->values();

        $taken = [$primary->bucket($tz)];
        $out = [];

        $wanted = [
            [ReasonKey::DifferentTimeOfDay, fn (CarbonImmutable $local) => ($local->hour < 12) !== ($anchor->hour < 12)],
            [ReasonKey::SameOrNextDay, function (CarbonImmutable $local) use ($anchor) {
                $days = (int) round($anchor->startOfDay()->diffInDays($local->startOfDay(), false));

                return $days === 0 || $days === 1;
            }],
            [ReasonKey::Weekend, fn (CarbonImmutable $local) => $local->isoWeekday() >= 6],
        ];

        foreach ($wanted as [$key, $matches]) {
            if ($key === ReasonKey::Weekend && ! $this->opensAtWeekends($tenant, $service)) {
                continue;
            }

            $found = $this->firstUnused($slots, $tz, $taken, fn (CarbonImmutable $local) => $matches($local));

            if ($found === null) {
                continue;
            }

            $out[] = $this->build($tenant, $service, $subject, $found, null, $key, $anchor);
            $taken[] = end($out)->bucket($tz);
        }

        while (count($out) < 3) {
            $endOfWeek = $anchor->endOfWeek(CarbonImmutable::SUNDAY);

            $last = $slots
                ->filter(fn (array $e) => $e['slot']->startsAt->timezone($tz)->lte($endOfWeek))
                ->filter(fn (array $e) => ! in_array($this->bucketOf($e['slot'], $tz), $taken, true))
                ->last();

            if ($last !== null) {
                $out[] = $this->build($tenant, $service, $subject, $last, null, ReasonKey::LastThisWeek, $anchor);
                $taken[] = end($out)->bucket($tz);

                continue;
            }

            $next = $this->firstUnused($slots, $tz, $taken, fn () => true);

            if ($next === null) {
                break;
            }

            $out[] = $this->build($tenant, $service, $subject, $next, null, ReasonKey::SameOrNextDay, $anchor);
            $taken[] = end($out)->bucket($tz);
        }

        usort($out, fn (Proposal $a, Proposal $b) => $a->startsAt <=> $b->startsAt);

        return $this->disambiguate($out, $tz);
    }

    /**
     * @param  list<Proposal>  $proposals
     * @return list<Proposal>
     */
    private function disambiguate(array $proposals, string $tz): array
    {
        $seen = [];
        $out = [];

        foreach ($proposals as $proposal) {
            if (! in_array($proposal->reason, $seen, true)) {
                $seen[] = $proposal->reason;
                $out[] = $proposal;

                continue;
            }

            $out[] = new Proposal(
                startsAt: $proposal->startsAt,
                endsAt: $proposal->endsAt,
                service: $proposal->service,
                staff: $proposal->staff,
                subject: $proposal->subject,
                reasonKey: $proposal->reasonKey,
                reason: $proposal->startsAt->timezone($tz)->format('l j M'),
                staffIds: $proposal->staffIds,
            );
            $seen[] = end($out)->reason;
        }

        return $out;
    }

    /**
     * @param  Collection<int, array{slot: Slot, staff: User|null}>  $slots
     * @param  list<string>  $taken
     * @return array{slot: Slot, staff: User|null}|null
     */
    private function firstUnused(Collection $slots, string $tz, array $taken, callable $matches): ?array
    {
        return $slots->first(function (array $entry) use ($tz, $taken, $matches) {
            if (in_array($this->bucketOf($entry['slot'], $tz), $taken, true)) {
                return false;
            }

            return $matches($entry['slot']->startsAt->timezone($tz));
        });
    }

    private function bucketOf(Slot $slot, string $tz): string
    {
        $local = $slot->startsAt->timezone($tz);

        return $local->toDateString().'|'.($local->hour < 12 ? 'am' : 'pm');
    }

    /** @return Collection<int, array{slot: Slot, staff: User|null}> */
    private function slots(Tenant $tenant, Service $service, CarbonImmutable $now): Collection
    {
        $days = min(self::LOOKAHEAD_DAYS, $tenant->horizonDays());

        $collection = $this->engine->slotsFor(
            $tenant,
            $service,
            $now,
            $now->addDays($days),
        );

        $staff = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('id');

        return collect(iterator_to_array($collection))
            ->map(fn (Slot $slot) => [
                'slot' => $slot,
                'staff' => $staff->get($slot->staffIds[0] ?? 0),
            ])
            ->filter(fn (array $entry) => $entry['staff'] !== null)
            ->values();
    }

    /** @param  Collection<int, array{slot: Slot, staff: User|null}>  $slots */
    private function stillWorking(Collection $slots, User $staff): bool
    {
        return $slots->contains(fn (array $entry) => in_array($staff->id, $entry['slot']->staffIds, true));
    }

    /** @param  array{slot: Slot, staff: User|null}|null  $entry */
    private function propose(
        Tenant $tenant,
        Service $service,
        ?Subject $subject,
        ?array $entry,
        ?User $staff,
        ReasonKey $key,
    ): ?Proposal {
        return $entry === null ? null : $this->build($tenant, $service, $subject, $entry, $staff, $key);
    }

    /** @param  array{slot: Slot, staff: User|null}  $entry */
    private function build(
        Tenant $tenant,
        Service $service,
        ?Subject $subject,
        array $entry,
        ?User $staff,
        ReasonKey $key,
        ?CarbonImmutable $anchorLocal = null,
    ): Proposal {
        $slot = $entry['slot'];

        $chosen = $staff !== null && in_array($staff->id, $slot->staffIds, true) ? $staff : $entry['staff'];

        return new Proposal(
            startsAt: $slot->startsAt->utc(),
            endsAt: $slot->startsAt->utc()->addMinutes($service->duration_minutes),
            service: $service,
            staff: $chosen,
            subject: $subject,
            reasonKey: $key,
            reason: $this->phrase($key, $slot, $chosen, $tenant->timezone, $anchorLocal),
            staffIds: $slot->staffIds,
        );
    }

    private function phrase(
        ReasonKey $key,
        Slot $slot,
        User $staff,
        string $timezone,
        ?CarbonImmutable $anchorLocal = null,
    ): string {
        $local = $slot->startsAt->timezone($timezone);
        $firstName = explode(' ', trim($staff->name))[0];
        $half = $local->hour < 12 ? 'morning' : 'afternoon';
        $sameDayAsProposal = $anchorLocal !== null && $anchorLocal->toDateString() === $local->toDateString();

        return match ($key) {
            ReasonKey::UsualDay => 'Your usual '.$local->format('l'),
            ReasonKey::UsualTime => 'Around your usual time',
            ReasonKey::DueNow => 'About due, and '.$firstName.' is free',
            ReasonKey::SoonestWithStaff => 'Soonest with '.$firstName,
            ReasonKey::FirstAvailable => 'First available',
            ReasonKey::SameOrNextDay => $sameDayAsProposal
                ? $local->format('l').', later'
                : $local->format('l').' '.$half,
            ReasonKey::DifferentTimeOfDay => $sameDayAsProposal
                ? $local->format('l').', later'
                : $local->format('l').' '.$half,
            ReasonKey::Weekend => $local->format('l').' '.$half,
            ReasonKey::LastThisWeek => 'Last one this week',
        };
    }

    /** @return Collection<int, Booking> */
    private function history(Tenant $tenant, Customer $customer, CarbonImmutable $now): Collection
    {
        return Booking::withoutGlobalScopes()
            ->with([
                'service' => fn ($query) => $query->withoutGlobalScopes(),
                'staff' => fn ($query) => $query->withoutGlobalScopes(),
                'subject' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('starts_at', '<', $now)
            ->whereIn('status', [BookingStatus::Completed->value, BookingStatus::Confirmed->value])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_DEPTH)
            ->get();
    }

    /** @param  Collection<int, Booking>  $history */
    private function typicalIntervalDays(Collection $history): ?int
    {
        $starts = $history
            ->map(fn (Booking $booking) => CarbonImmutable::parse($booking->starts_at)->utc())
            ->values();

        if ($starts->count() < 2) {
            return null;
        }

        $gaps = [];

        for ($i = 0; $i < $starts->count() - 1; $i++) {
            $gaps[] = (int) round($starts[$i + 1]->diffInDays($starts[$i]));
        }

        sort($gaps);
        $middle = intdiv(count($gaps), 2);

        $median = count($gaps) % 2 === 1
            ? $gaps[$middle]
            : (int) round(($gaps[$middle - 1] + $gaps[$middle]) / 2);

        return $median < self::MIN_CREDIBLE_INTERVAL_DAYS ? null : $median;
    }

    /** @param  Collection<int, Booking>  $history */
    private function usualWeekday(Collection $history, string $timezone): ?int
    {
        if ($history->count() < 2) {
            return null;
        }

        $counts = $history
            ->map(fn (Booking $b) => (int) CarbonImmutable::parse($b->starts_at)->timezone($timezone)->isoWeekday())
            ->countBy();

        $top = $counts->sortDesc()->keys()->first();

        return $top !== null && $history->count() < $counts[$top] * 2 ? (int) $top : null;
    }

    /** @param  Collection<int, Booking>  $history */
    private function usualMinuteOfDay(Collection $history, string $timezone): ?int
    {
        if ($history->count() < 2) {
            return null;
        }

        return (int) round($history
            ->map(function (Booking $booking) use ($timezone) {
                $local = CarbonImmutable::parse($booking->starts_at)->timezone($timezone);

                return $local->hour * 60 + $local->minute;
            })
            ->average());
    }

    /**
     * @param  Collection<int, Booking>  $history
     * @return ($relation is 'service' ? Service|null : User|null)
     */
    private function lastOf(Collection $history, string $relation): Service|User|null
    {
        $booking = $history->first();

        if ($booking === null) {
            return null;
        }

        return $relation === 'service' ? $booking->service : $booking->staff;
    }

    /** @param  Collection<int, Booking>  $history */
    private function lastSubject(Collection $history): ?Subject
    {
        return $history->first()?->subject;
    }

    private function defaultService(Tenant $tenant): ?Service
    {
        return Service::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();
    }

    private function opensAtWeekends(Tenant $tenant, Service $service): bool
    {
        $staffIds = $service->staff()->withoutGlobalScopes()->pluck('users.id');

        if ($staffIds->isEmpty()) {
            return false;
        }

        return AvailabilityRule::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('user_id', $staffIds)
            ->whereIn('weekday', [Weekday::Saturday->value, Weekday::Sunday->value])
            ->exists();
    }
}
