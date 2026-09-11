<?php

namespace App\Services\Booking;

use App\Enums\SlotOfferStatus;
use App\Models\Booking;
use App\Models\SlotOffer;
use App\Models\Tenant;
use App\Services\Waitlist\WaitlistOfferer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class FreedSlots
{
    private const MIN_SELLABLE_MINUTES = 15;

    public function __construct(private WaitlistOfferer $waitlist) {}

    /**
     * @param  Collection<int, Booking>  $all
     * @return array<int, array{is_freed: bool, is_refilled: bool, minutes: int, gap_starts_at: string|null, waiting: int, offers_sent: int}>
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

    private function waiting(Tenant $tenant, Booking $booking, CarbonImmutable $startsAt): int
    {
        if ($booking->service === null) {
            return 0;
        }

        return $this->waitlist->rankedMatches($tenant, $booking->service, $startsAt)->count();
    }

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
