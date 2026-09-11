<?php

namespace App\Services;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CustomerHistoryService
{
    public const OUTCOME_ATTENDED = 'attended';

    public const OUTCOME_NO_SHOW = 'no_show';

    public const OUTCOME_BOOKED = 'booked';

    public const OUTCOME_CANCELLED = 'cancelled';

    public const DEPOSIT_NOT_REQUIRED = 'No deposit required';

    public const DEPOSIT_ALWAYS_PAID = 'Always paid on booking';

    public const DEPOSIT_FORFEITED_ONCE = 'Forfeited once';

    public const DEPOSIT_FORFEITED_MANY = 'Forfeited %d times';

    public const DEPOSIT_PART_PAID = 'Paid %d of %d times';

    public const SOURCE_BOOKING_LINK = 'Booking link';

    public const SOURCE_DIARY = 'Added in the diary';

    public const BADGE_RELIABLE = 'Reliable';

    public const BADGE_WATCH = 'Watch — %d no-shows';

    public const TONE_NEUTRAL = 'neutral';

    public const TONE_WATCH = 'watch';

    public const SUGGESTED_RULE_MESSAGE = '%d missed visits in %d months. Take the full amount up front for this customer and the slot is never lost.';

    public function outcome(Booking $booking): string
    {
        return match ($booking->status) {
            BookingStatus::Completed => self::OUTCOME_ATTENDED,
            BookingStatus::NoShow => self::OUTCOME_NO_SHOW,
            BookingStatus::Cancelled, BookingStatus::Declined => self::OUTCOME_CANCELLED,
            default => self::OUTCOME_BOOKED,
        };
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, Booking>
     */
    public function settled(Collection $bookings): Collection
    {
        return $bookings
            ->filter(fn (Booking $booking) => in_array(
                $this->outcome($booking),
                [self::OUTCOME_ATTENDED, self::OUTCOME_NO_SHOW],
                true,
            ))
            ->sortBy(fn (Booking $booking) => $booking->starts_at?->getTimestamp() ?? 0)
            ->values();
    }

    public function amountPaid(Booking $booking): Money
    {
        if ($booking->status === BookingStatus::Completed) {
            return $booking->price_at_booking;
        }

        if ($booking->deposit_status === DepositStatus::Paid) {
            return $booking->deposit_at_booking;
        }

        return new Money(0, $booking->price_at_booking->currency);
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, mixed>
     */
    public function stats(Collection $bookings, string $timezone): array
    {
        $settled = $this->settled($bookings);
        $noShows = $settled->filter(fn (Booking $booking) => $this->outcome($booking) === self::OUTCOME_NO_SHOW);

        $currency = $bookings->first()?->price_at_booking->currency ?? 'GBP';
        $paid = $bookings->sum(fn (Booking $booking) => $this->amountPaid($booking)->amount);

        return [
            'visits' => $settled->count(),
            'no_shows' => $noShows->count(),
            'lifetime_value' => (new Money((int) $paid, $currency))->toArray(),
            'average_gap_days' => $this->averageGapDays($settled),
            'first_visit_on' => $settled->first()?->starts_at?->timezone($timezone)->format('Y-m-d'),
            'next_visit' => $this->nextVisit($bookings, $timezone),
        ];
    }

    /** @param  Collection<int, Booking>  $settled */
    public function averageGapDays(Collection $settled): ?int
    {
        if ($settled->count() < 2) {
            return null;
        }

        $dates = $settled
            ->map(fn (Booking $booking) => $booking->starts_at === null
                ? null
                : CarbonImmutable::parse($booking->starts_at)->startOfDay())
            ->filter()
            ->values();

        if ($dates->count() < 2) {
            return null;
        }

        $total = 0;

        for ($index = 1; $index < $dates->count(); $index++) {
            $total += $dates[$index - 1]->diffInDays($dates[$index]);
        }

        return (int) round($total / ($dates->count() - 1));
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, mixed>|null
     */
    public function nextVisit(Collection $bookings, string $timezone): ?array
    {
        $now = CarbonImmutable::now();

        $next = $bookings
            ->filter(fn (Booking $booking) => $booking->status === BookingStatus::Confirmed
                && $booking->starts_at !== null
                && $booking->starts_at->greaterThanOrEqualTo($now))
            ->sortBy(fn (Booking $booking) => $booking->starts_at->getTimestamp())
            ->first();

        if ($next === null) {
            return null;
        }

        $local = $next->starts_at->timezone($timezone);

        return [
            'booking_id' => $next->id,
            'date' => $local->format('Y-m-d'),
            'day_label' => $local->format('j M'),
            'time' => $local->format('H:i'),
            'service_name' => $next->service?->name,
        ];
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, mixed>
     */
    public function attendance(Collection $bookings, string $timezone): array
    {
        $settled = $this->settled($bookings);
        $kept = $settled->filter(fn (Booking $booking) => $this->outcome($booking) === self::OUTCOME_ATTENDED)->count();
        $missed = $settled->count() - $kept;

        $strip = $settled
            ->slice(-(int) config('customers.attendance_strip_length'))
            ->map(fn (Booking $booking) => $this->outcome($booking))
            ->values()
            ->all();

        return [
            'percentage' => $settled->count() === 0 ? null : (int) round(($kept / $settled->count()) * 100),
            'kept' => $kept,
            'booked' => $settled->count(),
            'strip' => $strip,
            'summary' => $this->attendanceSummary($bookings, $settled, $missed, $timezone),
            'deposit_behaviour' => $this->depositBehaviour($bookings),
            'books_through' => $this->booksThrough($bookings),
            'usual_slot' => $this->usualSlot($settled, $timezone),
        ];
    }

    /** @param  Collection<int, Booking>  $bookings */
    public function depositBehaviour(Collection $bookings): string
    {
        $required = $bookings->filter(fn (Booking $booking) => $booking->deposit_status !== DepositStatus::None
            || $booking->deposit_at_booking->amount > 0);

        if ($required->isEmpty()) {
            return self::DEPOSIT_NOT_REQUIRED;
        }

        $forfeited = $required->filter(fn (Booking $booking) => $booking->deposit_status === DepositStatus::Paid
            && in_array($booking->status, BookingStatus::vacating(), true))->count();

        if ($forfeited === 1) {
            return self::DEPOSIT_FORFEITED_ONCE;
        }

        if ($forfeited > 1) {
            return sprintf(self::DEPOSIT_FORFEITED_MANY, $forfeited);
        }

        $paid = $required->filter(fn (Booking $booking) => in_array($booking->deposit_status, [
            DepositStatus::Paid,
            DepositStatus::RefundPending,
            DepositStatus::Refunded,
        ], true))->count();

        if ($paid === $required->count()) {
            return self::DEPOSIT_ALWAYS_PAID;
        }

        return sprintf(self::DEPOSIT_PART_PAID, $paid, $required->count());
    }

    /** @param  Collection<int, Booking>  $bookings */
    public function booksThrough(Collection $bookings): ?string
    {
        if ($bookings->isEmpty()) {
            return null;
        }

        $online = $bookings->filter(fn (Booking $booking) => $booking->source === BookingSource::Online)->count();

        return $online >= ($bookings->count() - $online)
            ? self::SOURCE_BOOKING_LINK
            : self::SOURCE_DIARY;
    }

    /** @param  Collection<int, Booking>  $settled */
    public function usualSlot(Collection $settled, string $timezone): ?string
    {
        if ($settled->isEmpty()) {
            return null;
        }

        $local = $settled
            ->map(fn (Booking $booking) => $booking->starts_at?->timezone($timezone))
            ->filter()
            ->values();

        if ($local->isEmpty()) {
            return null;
        }

        $days = $local->countBy(fn ($moment) => $moment->format('l'));
        $topDay = $days->sortDesc()->keys()->first();
        $topDayShare = $days->get($topDay, 0) / $local->count();

        $parts = $local->countBy(function ($moment) {
            $hour = (int) $moment->format('G');

            return $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');
        });
        $topPart = $parts->sortDesc()->keys()->first();

        if ($topDayShare >= 0.5) {
            return $topDay.' '.$topPart;
        }

        $weekend = $local->filter(fn ($moment) => in_array($moment->format('l'), ['Saturday', 'Sunday'], true))->count();

        return ($weekend > $local->count() - $weekend ? 'Weekend' : 'Weekday').' '.$topPart;
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return list<array<string, mixed>>
     */
    public function cadence(Collection $bookings, string $timezone): array
    {
        $marks = $bookings
            ->filter(fn (Booking $booking) => $this->outcome($booking) !== self::OUTCOME_CANCELLED
                && $booking->starts_at !== null)
            ->sortBy(fn (Booking $booking) => $booking->starts_at->getTimestamp())
            ->values();

        $previous = null;
        $rows = [];

        foreach ($marks as $booking) {
            $day = CarbonImmutable::parse($booking->starts_at)->timezone($timezone);

            $rows[] = [
                'booking_id' => $booking->id,
                'date' => $day->format('Y-m-d'),
                'label' => $day->format('j M Y'),
                'time' => $day->format('H:i'),
                'outcome' => $this->outcome($booking),
                'service_name' => $booking->service?->name,
                'staff_name' => $booking->staff?->name,
                'paid' => $this->amountPaid($booking)->formatted(),
                'gap_days' => $previous === null ? null : (int) $previous->startOfDay()->diffInDays($day->startOfDay()),
            ];

            $previous = $day;
        }

        return $rows;
    }

    /** @param  Collection<int, Booking>  $bookings */
    public function noShowsInWindow(Collection $bookings): int
    {
        $since = CarbonImmutable::now()->subMonths((int) config('customers.watch_window_months'));

        return $bookings
            ->filter(fn (Booking $booking) => $this->outcome($booking) === self::OUTCOME_NO_SHOW
                && $booking->starts_at !== null
                && $booking->starts_at->greaterThanOrEqualTo($since))
            ->count();
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, string>|null
     */
    public function badge(Collection $bookings): ?array
    {
        $inWindow = $this->noShowsInWindow($bookings);

        if ($inWindow >= (int) config('customers.watch_no_show_count')) {
            return [
                'label' => sprintf(self::BADGE_WATCH, $inWindow),
                'tone' => self::TONE_WATCH,
            ];
        }

        $settled = $this->settled($bookings);

        if ($settled->count() < (int) config('customers.min_visits_for_reliability_label')) {
            return null;
        }

        $missed = $settled->filter(fn (Booking $booking) => $this->outcome($booking) === self::OUTCOME_NO_SHOW)->count();

        if ($missed === 0 || ($missed / $settled->count()) < (float) config('customers.reliable_no_show_threshold')) {
            return ['label' => self::BADGE_RELIABLE, 'tone' => self::TONE_NEUTRAL];
        }

        return null;
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, mixed>|null
     */
    public function suggestedRule(Customer $customer, Collection $bookings): ?array
    {
        if ($customer->suggested_rule_dismissed_at !== null || $customer->requires_full_payment_override) {
            return null;
        }

        $count = $this->noShowsInWindow($bookings);
        $window = (int) config('customers.watch_window_months');

        if ($count < (int) config('customers.watch_no_show_count')) {
            return null;
        }

        return [
            'no_show_count' => $count,
            'window_months' => $window,
            'message' => sprintf(self::SUGGESTED_RULE_MESSAGE, $count, $window),
        ];
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @param  Collection<int, Booking>  $settled
     */
    private function attendanceSummary(Collection $bookings, Collection $settled, int $missed, string $timezone): string
    {
        if ($settled->isEmpty()) {
            return $bookings->isEmpty()
                ? 'Nothing booked yet, so there is nothing to measure.'
                : 'Nothing kept and nothing missed so far — every booking was cancelled or is still to come.';
        }

        if ($missed === 0) {
            return $settled->count() === 1
                ? 'One visit so far, and it was kept.'
                : sprintf('Never a missed visit across %d bookings.', $settled->count());
        }

        $last = $settled
            ->filter(fn (Booking $booking) => $this->outcome($booking) === self::OUTCOME_NO_SHOW)
            ->last();

        $when = $last?->starts_at?->timezone($timezone)->format('j M Y');

        return $missed === 1
            ? sprintf('One missed visit, on %s.', $when)
            : sprintf('%d missed visits, the most recent on %s.', $missed, $when);
    }
}
