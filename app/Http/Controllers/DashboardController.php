<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Services\Booking\FreedSlots;
use App\Services\Rebooking\OverdueSubjects;
use App\Support\Money;
use App\Support\PendingRequestPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

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
                'timezone' => $tz,
            ],
            'band' => [
                'recovered' => $this->recovered($tenant->currency, $monthStart, $nextMonth),
                'overdue' => $overdue->summary($tenant),
                'deposits' => $this->depositsHeld($tenant->currency),
                'no_shows' => $this->noShowRate($monthStart, $nextMonth, $lastMonth),
            ],
            'today' => $this->today($tenant, $freed, $todayStart, $now),
            'diary' => $this->diaryDay($now),
            'attention' => $this->attention($tenant, $now),
            'pending_requests' => PendingRequestPayload::forTenant($tenant),
        ]);
    }

    /** @return array<string, mixed> */
    private function diaryDay(CarbonImmutable $now): array
    {
        $open = AvailabilityRule::query()
            ->distinct()
            ->pluck('weekday')
            ->map(fn ($weekday) => (int) ($weekday instanceof Weekday ? $weekday->value : $weekday))
            ->all();

        $openToday = in_array((int) $now->isoWeekday(), $open, true);
        $next = null;

        if ($open !== []) {
            for ($ahead = 1; $ahead <= 7; $ahead++) {
                $day = $now->addDays($ahead);

                if (in_array((int) $day->isoWeekday(), $open, true)) {
                    $next = ['date' => $day->format('Y-m-d'), 'label' => $day->format('l j F')];
                    break;
                }
            }
        }

        return [
            'date' => $now->format('Y-m-d'),
            'day' => $now->format('j M'),
            'weekday_plural' => $now->format('l').'s',
            'open_today' => $openToday,
            'next_open' => $next,
        ];
    }

    /** @return array<string, mixed> */
    private function attention(Tenant $tenant, CarbonImmutable $now): array
    {
        $unpaid = Booking::query()
            ->where('deposit_status', DepositStatus::Required->value)
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::Declined->value])
            ->where('starts_at', '>=', $now->utc())
            ->get(['id', 'created_at', 'deposit_at_booking']);

        $oldest = $unpaid->min('created_at');

        $waiting = WaitlistEntry::query()
            ->where('is_active', true)
            ->get(['id', 'created_at']);

        $longest = $waiting->min('created_at');

        return [
            'deposits' => [
                'count' => $unpaid->count(),
                'value' => (new Money((int) $unpaid->sum(fn (Booking $b) => $b->deposit_at_booking->amount), $tenant->currency))->formatted(),
                'oldest_days' => $oldest === null ? null : (int) $now->diffInDays(CarbonImmutable::parse($oldest), true),
            ],
            'waitlist' => [
                'count' => $waiting->count(),
                'longest_days' => $longest === null ? null : (int) $now->diffInDays(CarbonImmutable::parse($longest), true),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function recovered(string $currency, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Booking::query()
            ->whereNotNull('waitlist_entry_id')
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->utc())
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::Declined->value])
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

    /** @return array<string, mixed> */
    private function depositsHeld(string $currency): array
    {
        $rows = Booking::query()
            ->where('deposit_status', DepositStatus::Paid->value)
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::Declined->value])
            ->where('starts_at', '>=', CarbonImmutable::now('UTC'))
            ->get(['id', 'deposit_at_booking']);

        return [
            'value' => (new Money((int) $rows->sum(fn (Booking $b) => $b->deposit_at_booking->amount), $currency))->formatted(),
            'count' => $rows->count(),
        ];
    }

    /** @return array<string, mixed> */
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
            'change' => $current === null || $before === null
                ? null
                : sprintf('%+.1f', round($current - $before, 1)),
        ];
    }

    private function staffInToday(CarbonImmutable $todayStart, string $tz): string
    {
        $names = Booking::query()
            ->with('staff')
            ->where('starts_at', '>=', $todayStart->utc())
            ->where('starts_at', '<', $todayStart->addDay()->utc())
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::Declined->value])
            ->get()
            ->map(fn (Booking $booking) => $booking->staff?->name)
            ->filter()
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

    /** @return list<array<string, mixed>> */
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

    /** @param  Collection<int, string|null>  $notes */
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
