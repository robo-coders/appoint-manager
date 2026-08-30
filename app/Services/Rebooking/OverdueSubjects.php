<?php

namespace App\Services\Rebooking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Support\Money;
use App\Support\VerticalInterval;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who is due back, and what that is worth.
 *
 * Reads with `withoutGlobalScopes()` plus an explicit `tenant_id`. This walks
 * a tenant's whole customer base; a missing tenant predicate is a leak.
 */
final class OverdueSubjects
{
    public function __construct(private RebookInterval $intervals) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forTenant(Tenant $tenant, ?CarbonImmutable $today = null): Collection
    {
        $today = ($today ?? CarbonImmutable::now($tenant->timezone))->startOfDay();
        $todayUtc = $today->utc();
        $contactedCutoff = $todayUtc->subDays((int) config('rebooking.contacted_window_days'));

        $futureIds = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('subject_id')
            ->where('starts_at', '>', $todayUtc)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->pluck('subject_id');

        $subjects = Subject::withoutGlobalScopes()
            ->with(['customer' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $tenant->id)
            ->whereNull('rebook_stopped_at')
            ->where(fn ($query) => $query->whereNull('rebook_snoozed_until')->orWhere('rebook_snoozed_until', '<=', $todayUtc))
            ->where(fn ($query) => $query->whereNull('rebook_contacted_at')->orWhere('rebook_contacted_at', '<=', $contactedCutoff))
            ->whereNotIn('id', $futureIds)
            ->get();

        if ($subjects->isEmpty()) {
            return collect();
        }

        $lastVisits = $this->lastVisits($tenant, $subjects->pluck('id'));
        $services = Service::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('id');

        return $subjects
            ->map(function (Subject $subject) use ($tenant, $today, $lastVisits, $services) {
                $last = $lastVisits->get($subject->id);

                if ($last === null) {
                    return null;
                }

                $service = $services->get($last->service_id);

                if ($service === null) {
                    return null;
                }

                $days = $this->intervals->daysForLastVisit($subject, $last, $service);
                $lastLocal = CarbonImmutable::parse($last->starts_at)->timezone($tenant->timezone)->startOfDay();
                $due = $lastLocal->addDays($days);

                if ($due->gt($today)) {
                    return null;
                }

                $price = $service->price;

                return [
                    'subject_id' => $subject->id,
                    'subject_name' => $subject->name,
                    'customer_id' => $subject->customer_id,
                    'customer_name' => $subject->customer?->name,
                    'phone' => $subject->customer?->phone,
                    'service_name' => $service->name,
                    'due_on' => $due->toDateString(),
                    'due_label' => $due->format('j F'),
                    'interval_days' => $days,
                    'interval_label' => VerticalInterval::phrase($days),
                    'days_overdue' => (int) $due->diffInDays($today),
                    'price_amount' => $price->amount,
                    'price' => $price->formatted(),
                    'stopped' => false,
                    'snoozed_until' => $subject->rebook_snoozed_until?->toDateString(),
                    /*
                     * A customer who replied STOP stays on this list. Opting out
                     * of texts is not opting out of being a customer, and the
                     * salon can still pick up the phone — she just needs to be
                     * told that is the only way to reach them.
                     */
                    'opted_out' => $subject->customer?->sms_opted_out_at !== null,
                    'number_failing' => $subject->rebook_send_blocked_at !== null,
                ];
            })
            ->filter()
            ->sortByDesc('days_overdue')
            ->values();
    }

    /**
     * @return array{count: int, value: string, amount: int, noun: string}
     */
    public function summary(Tenant $tenant, ?CarbonImmutable $today = null): array
    {
        $rows = $this->forTenant($tenant, $today);
        $amount = (int) $rows->sum('price_amount');
        $vertical = $tenant->vertical();

        return [
            'count' => $rows->count(),
            'amount' => $amount,
            'value' => (new Money($amount, $tenant->currency))->formatted(),
            'noun' => $rows->count() === 1
                ? (string) ($vertical['subject_singular'] ?? 'subject')
                : (string) ($vertical['subject_plural'] ?? 'subjects'),
        ];
    }

    /**
     * Stopped subjects, so the operator can start chasing again.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function stoppedForTenant(Tenant $tenant): Collection
    {
        return Subject::withoutGlobalScopes()
            ->with(['customer' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('rebook_stopped_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Subject $subject) => [
                'subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'customer_name' => $subject->customer?->name,
                'stopped_on' => $subject->rebook_stopped_at?->toDateString(),
            ]);
    }

    /**
     * @param  Collection<int, int>  $subjectIds
     * @return Collection<int, Booking>
     */
    private function lastVisits(Tenant $tenant, Collection $subjectIds): Collection
    {
        $bookings = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('subject_id', $subjectIds)
            ->whereIn('status', [BookingStatus::Completed->value, BookingStatus::Confirmed->value])
            ->where('starts_at', '<=', CarbonImmutable::now('UTC'))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        return $bookings->unique('subject_id')->keyBy('subject_id');
    }
}
