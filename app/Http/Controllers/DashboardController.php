<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\Booking\FreedSlots;
use App\Services\Rebooking\OverdueSubjects;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The overview: recovered, overdue, deposits, no-shows, and today.
 *
 * The band is deliberately not four equal cards. Recovered and overdue take
 * the weight (sunk, 34px); deposits and no-shows stay 20px. See
 * `public/mockups/dashboard.html`.
 */
class DashboardController extends Controller
{
    public function __invoke(FreedSlots $freed, OverdueSubjects $overdue): Response
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $tz = $tenant->timezone;
        $now = CarbonImmutable::now($tz);
        $monthStart = $now->startOfMonth();
        $nextMonth = $monthStart->addMonth();
        $lastMonth = $monthStart->subMonth();
        $todayStart = $now->startOfDay();

        return Inertia::render('Dashboard', [
            'heading' => [
                'date' => $now->format('l j F'),
                'tenant' => $tenant->name,
                'staff_today' => $this->staffInToday($todayStart, $tz),
            ],
            'band' => [
                'recovered' => $this->recovered($tenant->currency, $monthStart, $nextMonth),
                'overdue' => $overdue->summary($tenant),
                'deposits' => $this->depositsHeld($tenant->currency),
                'no_shows' => $this->noShowRate($monthStart, $nextMonth, $lastMonth),
            ],
            'today' => $this->today($tenant, $freed, $todayStart, $now),
        ]);
    }

    /**
     * **The sales pitch, so it has to be exact.**
     *
     * Counts `bookings` rows whose `waitlist_entry_id` is not null — set once,
     * on claim, by `BookingService::claimOffer`, and never denormalised — that
     * start inside the current calendar month in the tenant's timezone and are
     * not cancelled. The figure is the sum of `price_at_booking`: the money on
     * the books this month from appointments that exist only because somebody
     * claimed a waitlist offer for an hour that had been given up.
     *
     * `starts_at`, not `created_at`: the question is how much of this month's
     * revenue was recovered, not how much clicking happened this month. A slot
     * offered in July for an August appointment is August's recovery.
     *
     * Cancelled rows are excluded because a refilled slot that was then
     * cancelled again recovered nothing. Pending rows are included — the hour
     * is held and the appointment is real — but counted separately in the
     * sub-line, because a deposit that never lands is money not yet recovered.
     *
     * @return array<string, mixed>
     */
    private function recovered(string $currency, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Booking::query()
            ->whereNotNull('waitlist_entry_id')
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->utc())
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->get(['id', 'status', 'price_at_booking']);

        $unconfirmed = $rows->where('status', BookingStatus::Pending)->count();
        $total = $rows->sum(fn (Booking $booking) => $booking->price_at_booking->amount);

        return [
            'value' => (new Money((int) $total, $currency))->formatted(),
            'count' => $rows->count(),
            'month' => $from->format('F'),
            'unconfirmed' => $unconfirmed,
        ];
    }

    /**
     * Money the salon is holding right now against appointments that have not
     * happened: `deposit_status = paid`, not cancelled, starting in the future.
     *
     * The previous version summed deposits *taken this week*, which is a
     * different quantity wearing the same label — it counts a deposit for an
     * appointment that has already been and gone, and misses one taken last
     * month for next Tuesday.
     *
     * @return array<string, mixed>
     */
    private function depositsHeld(string $currency): array
    {
        $rows = Booking::query()
            ->where('deposit_status', DepositStatus::Paid->value)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->where('starts_at', '>=', CarbonImmutable::now('UTC'))
            ->get(['id', 'deposit_at_booking']);

        return [
            'value' => (new Money((int) $rows->sum(fn (Booking $b) => $b->deposit_at_booking->amount), $currency))->formatted(),
            'count' => $rows->count(),
        ];
    }

    /**
     * No-shows as a share of appointments that actually finished — completed
     * plus no-show. Pending and confirmed are excluded: an appointment that has
     * not happened yet cannot have been missed, and counting it flatters the
     * rate every time the diary fills up.
     *
     * @return array<string, mixed>
     */
    private function noShowRate(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $previous): array
    {
        $rate = function (CarbonImmutable $start, CarbonImmutable $end): ?float {
            $finished = Booking::query()
                ->where('starts_at', '>=', $start->utc())
                ->where('starts_at', '<', $end->utc())
                ->whereIn('status', [BookingStatus::Completed->value, BookingStatus::NoShow->value])
                ->get(['id', 'status']);

            if ($finished->isEmpty()) {
                return null;
            }

            return 100 * $finished->where('status', BookingStatus::NoShow)->count() / $finished->count();
        };

        $current = $rate($from, $to);
        $before = $rate($previous, $from);

        return [
            'value' => $current === null ? '—' : number_format($current, 1).'%',
            'previous' => $before === null ? null : number_format($before, 1).'%',
            'previous_month' => $previous->format('F'),
            'direction' => $current === null || $before === null ? null : ($current <= $before ? 'down' : 'up'),
        ];
    }

    /** Who is actually working today, for the sub-line under the date. */
    private function staffInToday(CarbonImmutable $todayStart, string $tz): string
    {
        $names = Booking::query()
            ->with('staff')
            ->where('starts_at', '>=', $todayStart->utc())
            ->where('starts_at', '<', $todayStart->addDay()->utc())
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->get()
            ->map(fn (Booking $booking) => $booking->staff?->name)
            ->filter()
            // First names: this is a sub-line, not a staff list.
            ->map(fn (string $name) => explode(' ', $name)[0])
            ->unique()
            ->values()
            ->all();

        if ($names === []) {
            return 'Nobody booked in today';
        }

        if (count($names) === 1) {
            return $names[0].' in today';
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last.' in today';
    }

    /**
     * Today as a timeline: past appointments muted with no detail, the current
     * one carrying an extra line, and freed slots as the rows that need doing
     * something about.
     *
     * Cancelled bookings are loaded here rather than filtered out — the same
     * fix as the diary, and for the same reason. See `FreedSlots`.
     *
     * @return list<array<string, mixed>>
     */
    private function today(Tenant $tenant, FreedSlots $freed, CarbonImmutable $todayStart, CarbonImmutable $now): array
    {
        $tz = $tenant->timezone;

        $rows = Booking::query()
            ->with(['customer', 'service', 'staff', 'subject'])
            ->where('starts_at', '>=', $todayStart->utc())
            ->where('starts_at', '<', $todayStart->addDay()->utc())
            ->orderBy('starts_at')
            ->get();

        $annotations = $freed->annotate($tenant, $rows);
        $nowUtc = $now->utc();

        // One customer-notes lookup for the whole day rather than one per row.
        $notes = Customer::query()
            ->whereIn('id', $rows->pluck('customer_id')->filter()->unique())
            ->pluck('notes', 'id');

        return $rows
            ->reject(fn (Booking $booking) => ($annotations[$booking->id]['is_refilled'] ?? false))
            ->map(function (Booking $booking) use ($tz, $nowUtc, $annotations, $notes) {
                $starts = CarbonImmutable::parse($booking->starts_at)->utc();
                $ends = CarbonImmutable::parse($booking->ends_at)->utc();
                $annotation = $annotations[$booking->id] ?? null;
                $current = $starts->lte($nowUtc) && $ends->gt($nowUtc) && $booking->occupiesTime();

                return [
                    'id' => $booking->id,
                    'time' => $starts->timezone($tz)->format('H:i'),
                    'customer' => $booking->customer?->name,
                    'subject' => $booking->subject_id ? $booking->subject?->name : null,
                    'service' => $booking->service?->name,
                    'staff' => $booking->staff?->name,
                    'amount' => $booking->price_at_booking->formatted(),
                    'status' => $booking->status->value,
                    'past' => $ends->lte($nowUtc),
                    'current' => $current,
                    // Only the current appointment earns the extra line, so
                    // only the current appointment pays for building it.
                    'detail' => $current ? $this->detailLine($booking, $starts, $nowUtc, $notes) : null,
                    'freed' => $annotation && $annotation['is_freed'] ? [
                        'minutes' => $annotation['minutes'],
                        'waiting' => $annotation['waiting'],
                        'offers_sent' => $annotation['offers_sent'],
                        'deposit_kept' => $booking->deposit_status === DepositStatus::Paid,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "In the chair 14 min · deposit paid · first visit, nervous with clippers"
     *
     * @param  Collection<int, string|null>  $notes
     */
    private function detailLine(Booking $booking, CarbonImmutable $starts, CarbonImmutable $now, Collection $notes): string
    {
        $parts = ['In the chair '.(int) round($starts->diffInMinutes($now)).' min'];

        $parts[] = match ($booking->deposit_status) {
            DepositStatus::Paid => 'deposit paid',
            DepositStatus::Required => 'deposit outstanding',
            default => 'no deposit',
        };

        $earlier = Booking::query()
            ->where('customer_id', $booking->customer_id)
            ->where('starts_at', '<', $starts)
            ->whereIn('status', [BookingStatus::Completed->value, BookingStatus::Confirmed->value])
            ->exists();

        $note = trim((string) ($notes[$booking->customer_id] ?? ''));

        if (! $earlier) {
            $parts[] = $note === '' ? 'first visit' : 'first visit, '.lcfirst($note);
        } elseif ($note !== '') {
            $parts[] = lcfirst($note);
        }

        return implode(' · ', $parts);
    }
}
