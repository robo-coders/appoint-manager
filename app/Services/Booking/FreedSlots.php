<?php

namespace App\Services\Booking;

use App\Enums\SlotOfferStatus;
use App\Models\Booking;
use App\Models\SlotOffer;
use App\Models\Tenant;
use App\Services\Waitlist\WaitlistOfferer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A freed slot is a cancellation that left a hole in the day.
 *
 * Both the diary and the dashboard used to filter cancelled bookings out of
 * their queries entirely — `where('status', '!=', 'cancelled')`. That filter
 * hides the single most valuable row on either screen: an hour and a half of a
 * groomer's afternoon that is now empty and that three people on the waitlist
 * would take today. The whole waitlist feature is invisible if the screens
 * cannot see the gap it exists to fill.
 *
 * Not every cancellation is a freed slot, which is why this class exists rather
 * than the queries simply dropping the filter:
 *
 *   - **Refilled.** Somebody claimed the offer, or the salon rebooked the hour
 *     by hand. There is a live booking sitting in that staff member's diary
 *     over the same minutes, and it is already drawn. Showing the cancellation
 *     as well would report an empty hour that is not empty.
 *   - **Past.** A slot that has already come and gone cannot be recovered.
 *     It stays visible in the diary as history, but it is not an opportunity
 *     and it does not get the accent.
 *
 * ## Refilled is measured, not asserted
 *
 * The first version of this treated *any* overlap as refilled, and it was
 * wrong in a way the demo tenant caught immediately: Marek's 15:30–17:00
 * cancellation overlaps his own 16:30–17:15 appointment by 30 minutes, so the
 * whole freed slot — the one with three people waiting for it — disappeared off
 * both screens.
 *
 * So the live bookings are *subtracted* from the cancelled window and what is
 * left is measured. The number reported is the largest single uninterrupted
 * gap, because two disconnected 30-minute holes are not an hour you can sell,
 * and the gap's own start is reported too — a 90-minute cancellation with an
 * appointment across its tail is a slot that begins where it always did but
 * ends sooner.
 *
 * Below `MIN_SELLABLE_MINUTES` there is nothing to offer anybody, and the
 * cancellation is drawn as history rather than as an opportunity.
 *
 * The class is a pure read. It writes nothing and sends nothing — offering the
 * slot is `WaitlistOfferer`'s job, and it happens when somebody presses the
 * button this data draws.
 */
final class FreedSlots
{
    /**
     * The shortest gap worth calling a freed slot.
     *
     * A quarter of an hour is the finest granularity the booking engine works
     * in (`booking.slot_granularity_minutes`), so anything under it cannot be
     * offered to anybody however it is drawn.
     */
    private const MIN_SELLABLE_MINUTES = 15;

    public function __construct(private WaitlistOfferer $waitlist) {}

    /**
     * Annotate every cancelled booking in `$all` against the live bookings in
     * the same collection.
     *
     * `$all` must be the *whole* window — cancelled rows included — because a
     * cancellation can only be judged refilled against its neighbours. Pass a
     * filtered collection and every cancellation looks like a gap.
     *
     * @param  Collection<int, Booking>  $all
     * @return array<int, array{is_freed: bool, is_refilled: bool, minutes: int, gap_starts_at: string|null, waiting: int, offers_sent: int}>
     *                                                                                                                                        Keyed by booking id. Only cancelled bookings appear.
     */
    public function annotate(Tenant $tenant, Collection $all): array
    {
        $live = $all->filter(fn (Booking $booking) => $booking->occupiesTime())->values();
        $now = CarbonImmutable::now('UTC');
        $out = [];

        foreach ($all as $booking) {
            if ($booking->occupiesTime()) {
                continue;
            }

            $starts = CarbonImmutable::parse($booking->starts_at)->utc();
            $ends = CarbonImmutable::parse($booking->ends_at)->utc();

            $gap = $this->largestGap($live, $booking, $starts, $ends);

            // Nothing left of the hour: whatever took it is already on screen.
            $refilled = $gap === null;
            $recoverable = ! $refilled && $gap['ends']->gt($now);

            $out[$booking->id] = [
                'is_freed' => $recoverable,
                'is_refilled' => $refilled,
                'minutes' => $gap === null ? 0 : (int) round($gap['starts']->diffInMinutes($gap['ends'])),
                'gap_starts_at' => $gap === null ? null : $gap['starts']->toIso8601String(),
                'waiting' => $recoverable ? $this->waiting($tenant, $booking, $gap['starts']) : 0,
                'offers_sent' => $recoverable ? $this->offersSent($booking, $starts) : 0,
            ];
        }

        return $out;
    }

    /**
     * What is genuinely still open inside a cancelled window, as one interval.
     *
     * Every live booking for the same staff member is cut out of the window and
     * the widest surviving piece is returned. Null when nothing worth selling
     * survives.
     *
     * @param  Collection<int, Booking>  $live
     * @return array{starts: CarbonImmutable, ends: CarbonImmutable}|null
     */
    private function largestGap(
        Collection $live,
        Booking $booking,
        CarbonImmutable $starts,
        CarbonImmutable $ends,
    ): ?array {
        /** @var list<array{starts: CarbonImmutable, ends: CarbonImmutable}> $pieces */
        $pieces = [['starts' => $starts, 'ends' => $ends]];

        foreach ($live as $other) {
            if ($other->staff_id !== $booking->staff_id) {
                continue;
            }

            $cutFrom = CarbonImmutable::parse($other->starts_at)->utc();
            $cutTo = CarbonImmutable::parse($other->ends_at)->utc();
            $next = [];

            foreach ($pieces as $piece) {
                // Untouched.
                if ($cutTo->lte($piece['starts']) || $cutFrom->gte($piece['ends'])) {
                    $next[] = $piece;

                    continue;
                }

                if ($cutFrom->gt($piece['starts'])) {
                    $next[] = ['starts' => $piece['starts'], 'ends' => $cutFrom];
                }

                if ($cutTo->lt($piece['ends'])) {
                    $next[] = ['starts' => $cutTo, 'ends' => $piece['ends']];
                }
            }

            $pieces = $next;
        }

        $best = null;

        foreach ($pieces as $piece) {
            $minutes = $piece['starts']->diffInMinutes($piece['ends']);

            if ($minutes < self::MIN_SELLABLE_MINUTES) {
                continue;
            }

            if ($best === null || $minutes > $best['starts']->diffInMinutes($best['ends'])) {
                $best = $piece;
            }
        }

        return $best;
    }

    /**
     * How many people would actually be texted if the salon pressed the button.
     *
     * The same matcher the blast itself uses, so the count on the button and
     * the number of messages that go out cannot disagree. A count taken from
     * "active waitlist entries for this service" would be larger than the
     * truth, because it ignores day and time-of-day preferences.
     */
    private function waiting(Tenant $tenant, Booking $booking, CarbonImmutable $startsAt): int
    {
        if ($booking->service === null) {
            return 0;
        }

        return $this->waitlist->rankedMatches($tenant, $booking->service, $startsAt)->count();
    }

    /** Offers already out for this exact slot and still live. */
    private function offersSent(Booking $booking, CarbonImmutable $startsAt): int
    {
        return SlotOffer::withoutGlobalScopes()
            ->where('tenant_id', $booking->tenant_id)
            ->where('staff_id', $booking->staff_id)
            ->where('starts_at', $startsAt)
            ->where('status', SlotOfferStatus::Sent->value)
            ->where('expires_at', '>', now())
            ->count();
    }
}
