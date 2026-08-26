<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
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

/**
 * Proposes one finished appointment, and three ways out of it.
 *
 * The public booking page used to make the customer assemble an appointment out
 * of two independent choices — a date, then a time — and hold the constraint
 * ("90 minutes, with Ana, some evening after work") in their head while they
 * did it. This class exists so the page can state a finished appointment and
 * ask them to accept it instead.
 *
 * **Pure and side-effect free.** It reads: bookings, availability rules, time
 * off. It writes nothing, sends nothing, and mutates no cache. Calling it twice
 * changes nothing about the world, which is what makes it safe to call on every
 * page render and trivial to test against the demo tenant.
 *
 * ---
 *
 * ## The ranking rule, and why it is the same code as the explanation
 *
 * A proposal the customer cannot argue with is a proposal they cannot correct.
 * "Tuesday 10 March at 09:45" on its own is a guess wearing a confident face;
 * "Your usual Tuesday — Tuesday 10 March at 09:45" is a claim they can check
 * against what they know, and reject in one tap if it is wrong.
 *
 * So the reason is not a label applied to a slot after ranking. It *is* the
 * ranking: every candidate is tested against `ReasonKey` in order, and the
 * first phrase that honestly describes it is both its rank and its explanation.
 * A slot that matches no phrase is not proposed. That constraint is what stops
 * this drifting into "some slot, with a nice caption".
 *
 * ### Returning customers
 *
 * Recognised through the manage-link cookie or a reminder link — never through
 * a guess at an email address. They get the same service, the same staff and
 * the same subject as last time, and the first slot at or after their typical
 * interval: the median gap across their last three bookings, falling back to
 * the service's `suggested_interval_days`.
 *
 * Their slot is then described by the strongest true thing about it:
 *
 * | | reason | when |
 * |---|---|---|
 * | 1 | `usual_day` | falls on the weekday they usually come |
 * | 2 | `usual_time` | different day, but within an hour of the time they usually come |
 * | 3 | `due_now` | at or after their interval, nothing else matches — including everybody on their first return, because one visit is not a habit |
 * | 4 | `soonest_with_staff` | nothing free at or after the interval — their groomer, sooner |
 * | 5 | `first_available` | their groomer has gone. The next slot, anyone |
 *
 * ### New customers
 *
 * Their chosen service, the first available slot, any staff: `first_available`.
 *
 * ### The three alternatives
 *
 * Deliberately spread, because three consecutive quarter-hours on one morning
 * are not three choices — they are one choice rendered three times, and they
 * are what a naive "next three slots" produces every single time.
 *
 *   1. later the same day, or the next day (`same_or_next_day`)
 *   2. the other half of the day (`different_time_of_day`)
 *   3. a weekend, if the salon opens at weekends (`weekend`)
 *
 * Any of those that cannot be filled falls through to the last slot of the
 * proposal's week (`last_this_week`) and then to the next unused slot in a
 * bucket nobody has taken. `bucket()` is date-plus-half-day, and no two
 * proposals on the page may share one — which is the rule that makes "never
 * three consecutive slots on one morning" structural rather than a hope.
 */
final class AppointmentSuggester
{
    /** How far ahead to look for the primary proposal, in days. */
    private const LOOKAHEAD_DAYS = 42;

    /** How many previous bookings define a customer's own interval. */
    private const HISTORY_DEPTH = 3;

    /**
     * Below this, a measured gap is not a rhythm.
     *
     * Found against the demo tenant, where plenty of clients showed a median
     * gap of one day — because they have two dogs and bring them on
     * consecutive days, not because anybody is groomed daily. Proposing "you
     * are due tomorrow" off that is confidently wrong in exactly the way this
     * class is built to avoid, so a sub-weekly median is treated as no rhythm
     * at all and the service's own interval is used instead.
     */
    private const MIN_CREDIBLE_INTERVAL_DAYS = 7;

    /**
     * A slot counts as "around their usual time" if it starts within this of it.
     *
     * An hour, and the phrase says "around" rather than naming a time. It was
     * 90 minutes and the phrase read "Your usual time, 12:30" for somebody who
     * always comes at 14:00 — a sentence that contradicts itself in the same
     * breath, and precisely the confidently wrong claim this design exists to
     * rule out.
     */
    private const USUAL_TIME_TOLERANCE_MINUTES = 60;

    public function __construct(private AvailabilityEngine $engine) {}

    /**
     * @param  Service|null  $service  What they chose. Null means "work it out",
     *                                 which for a returning customer is whatever
     *                                 they had last time.
     * @param  User|null  $staff  A specific request. Null means "whoever", except
     *                            for a returning customer, who gets their own.
     * @param  CarbonImmutable|null  $now  Injected so tests do not have to travel.
     */
    public function suggest(
        Tenant $tenant,
        ?Customer $customer = null,
        ?Service $service = null,
        ?User $staff = null,
        ?CarbonImmutable $now = null,
    ): Suggestion {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $history = $customer === null ? collect() : $this->history($tenant, $customer, $now);
        $returning = $history->isNotEmpty();

        $service = $service ?? $this->lastOf($history, 'service') ?? $this->defaultService($tenant);

        if ($service === null) {
            return new Suggestion(null, [], $returning, $customer, null, null, null);
        }

        $subject = $returning ? $this->lastSubject($history) : null;
        $usualStaff = $staff ?? ($returning ? $this->lastOf($history, 'staff') : null);
        $intervalDays = $returning ? $this->typicalIntervalDays($history) : null;

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

    // -----------------------------------------------------------------------
    // The primary proposal
    // -----------------------------------------------------------------------

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
        // A new customer, or somebody whose groomer has left: first free slot,
        // anyone. There is exactly one honest thing to say about it.
        if ($history->isEmpty() || $usualStaff === null || ! $this->stillWorking($slots, $usualStaff)) {
            return $this->propose($tenant, $service, $subject, $slots->first(), null, ReasonKey::FirstAvailable);
        }

        $withStaff = $slots->filter(fn (array $entry) => in_array($usualStaff->id, $entry['slot']->staffIds, true))->values();

        if ($withStaff->isEmpty()) {
            return $this->propose($tenant, $service, $subject, $slots->first(), null, ReasonKey::FirstAvailable);
        }

        $due = $now->addDays($intervalDays ?? $service->suggestedIntervalDays());
        $atOrAfterDue = $withStaff->filter(fn (array $entry) => $entry['slot']->startsAt->gte($due))->values();

        /*
         * Nothing free at or after they are due — the diary is full that far
         * out, or the horizon is shorter than their interval. Their groomer,
         * sooner, and say so rather than pretending this is their usual rhythm.
         */
        if ($atOrAfterDue->isEmpty()) {
            return $this->propose($tenant, $service, $subject, $withStaff->first(), $usualStaff, ReasonKey::SoonestWithStaff);
        }

        $usualWeekday = $this->usualWeekday($history, $tenant->timezone);
        $usualMinute = $this->usualMinuteOfDay($history, $tenant->timezone);

        // Their weekday, if it comes round inside the horizon.
        if ($usualWeekday !== null) {
            $onUsualDay = $atOrAfterDue->first(
                fn (array $entry) => (int) $entry['slot']->startsAt->timezone($tenant->timezone)->isoWeekday() === $usualWeekday
            );

            if ($onUsualDay !== null) {
                return $this->propose($tenant, $service, $subject, $onUsualDay, $usualStaff, ReasonKey::UsualDay);
            }
        }

        // Not their day, but their time.
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

    // -----------------------------------------------------------------------
    // The three ways out
    // -----------------------------------------------------------------------

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

        /*
         * Only slots at or after the proposal. An alternative five weeks
         * *earlier* than a headline that says "your usual Tuesday" argues with
         * the proposal instead of offering a way out of it — and a customer who
         * genuinely wants sooner has `Pick another day`, which is what that
         * control is for.
         */
        $slots = $slots->filter(fn (array $e) => $e['slot']->startsAt->gte($primary->startsAt))->values();

        // Every proposal already on the page. Nothing may land in one of these,
        // which is what keeps the three alternatives from being three ways of
        // saying "Tuesday morning".
        $taken = [$primary->bucket($tz)];
        $out = [];

        /*
         * A list of pairs, not a keyed map: PHP array keys cannot be enum cases.
         *
         * The other half of the day is looked for first, and it takes the
         * nearest one — usually the proposal's own afternoon. `same_or_next_day`
         * then cannot have it, so it reaches the following morning, and the set
         * alternates morning/afternoon instead of stacking three afternoons.
         */
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

        // Backfill. "Last one this week" first, because a week running out is a
        // real reason; then anything at all in an unused bucket, which is the
        // only case where the phrase has to be generic.
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
     * Two alternatives that read the same are one alternative shown twice.
     *
     * `bucket()` keeps them on different days, but a salon that opens Saturdays
     * and is busy for a fortnight produced "Saturday morning" twice — the same
     * words for two different weeks. The second one takes the date instead of
     * the half of the day, which is short enough for the row and cannot be
     * mistaken for the first.
     *
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

    // -----------------------------------------------------------------------
    // Slots
    // -----------------------------------------------------------------------

    /**
     * Every bookable start inside the lookahead, in order, each paired with the
     * staff who are free for it.
     *
     * @return Collection<int, array{slot: Slot, staff: User|null}>
     */
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

        // The requested staff member if they are free for this slot; otherwise
        // whoever is. Never a name the customer would then not get.
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

    /**
     * The short phrase. Sentence case, no exclamation marks, and always a claim
     * the customer can check — never "Recommended for you", which says nothing
     * and can therefore never be wrong.
     */
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
            // "Tuesday, later" only when it really is later the same day.
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

    // -----------------------------------------------------------------------
    // History
    // -----------------------------------------------------------------------

    /**
     * Their last few appointments, newest first.
     *
     * Cancellations and no-shows are excluded: neither is evidence of a rhythm,
     * and a cancelled appointment in the middle of a run would double the
     * measured gap either side of it.
     *
     * @return Collection<int, Booking>
     */
    private function history(Tenant $tenant, Customer $customer, CarbonImmutable $now): Collection
    {
        /*
         * The eager loads carry `withoutGlobalScopes()` of their own.
         * `withoutGlobalScopes()` on the outer query does not reach the
         * relations, and `BelongsToTenant` needs a `TenantContext` this class
         * must never depend on — the public booking host is unauthenticated and
         * has none. Without it `$booking->service` came back null and a
         * returning customer was silently handed the salon's *first* service
         * instead of their own.
         */
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
            // `id` breaks the tie. Two appointments starting at the same
            // instant is not hypothetical — a dog and a cat in one visit — and
            // without it "the same service as last time" is whichever row the
            // database happened to hand back first.
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_DEPTH)
            ->get();
    }

    /**
     * The median gap across their last three bookings.
     *
     * Three bookings give two gaps, and the median of two is their mean — which
     * is the point of taking a median rather than a mean over the whole run: it
     * is the one gap in the middle that survives, so a single six-month absence
     * does not move the rhythm of somebody who otherwise comes every five weeks.
     *
     * Fewer than two previous bookings is not a rhythm. Null, and the caller
     * falls back to the service's own interval.
     *
     * @param  Collection<int, Booking>  $history
     */
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
            // Newest first, so the earlier booking is the *next* one along.
            $gaps[] = (int) round($starts[$i + 1]->diffInDays($starts[$i]));
        }

        sort($gaps);
        $middle = intdiv(count($gaps), 2);

        $median = count($gaps) % 2 === 1
            ? $gaps[$middle]
            : (int) round(($gaps[$middle - 1] + $gaps[$middle]) / 2);

        return $median < self::MIN_CREDIBLE_INTERVAL_DAYS ? null : $median;
    }

    /**
     * The weekday they come on, when there is one they clearly favour.
     *
     * A strict majority, not a plurality: two of three is a habit, one of three
     * is a coincidence, and "your usual Tuesday" said about a coincidence is
     * exactly the confidently wrong claim this whole design is trying to avoid.
     *
     * @param  Collection<int, Booking>  $history
     */
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

    /**
     * Their usual start, as minutes past midnight. The mean is right here: a
     * customer who comes at 09:00 and 10:00 usually comes mid-morning.
     *
     * @param  Collection<int, Booking>  $history
     */
    private function usualMinuteOfDay(Collection $history, string $timezone): ?int
    {
        // One appointment is a data point, not a preference. Claiming "your
        // usual time" off the back of a single visit is exactly the confidently
        // wrong statement the reason line exists to avoid.
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

    /**
     * The salon's own default when nobody has chosen: the first active service.
     * Ordered the way the salon ordered it, which is the closest thing to an
     * opinion the data holds.
     */
    private function defaultService(Tenant $tenant): ?Service
    {
        return Service::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();
    }

    /**
     * Does anyone who can do this service work a Saturday or a Sunday?
     *
     * Asked of the availability rules rather than of the slots, so a salon that
     * opens on Saturdays but happens to be fully booked for six weeks is still
     * a weekend salon — it just has no weekend slot to offer this time.
     */
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
