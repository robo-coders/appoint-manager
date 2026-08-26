<?php

namespace App\Services\Booking;

use App\Models\Service;
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One finished appointment, and the sentence that justifies it.
 *
 * The reason is not decoration and it is not a label bolted on afterwards. A
 * proposal that cannot say why it is the right appointment in a short phrase is
 * the wrong proposal, and the ranking that produced it needs changing — a
 * confidently wrong suggestion is worse than a calendar, because the customer
 * cannot see the reasoning well enough to correct it. So the reason is built by
 * the same code that picks the slot, from the same facts, and there is no way
 * to construct one of these without it.
 *
 * `reason` is the phrase a person reads. `reasonKey` is the same thing for
 * tests and analytics, so an assertion about ranking does not have to match on
 * English.
 */
final readonly class Proposal
{
    /**
     * @param  list<int>  $staffIds  Everyone free at this instant. `staff` is the
     *                               one being proposed; the rest are why a
     *                               different staff member can be swapped in
     *                               without re-querying.
     */
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public Service $service,
        public User $staff,
        public ?Subject $subject,
        public ReasonKey $reasonKey,
        public string $reason,
        public array $staffIds = [],
    ) {}

    /** The day-of-week and half of the day, which is what "spread" is measured on. */
    public function bucket(string $timezone): string
    {
        $local = $this->startsAt->timezone($timezone);

        return $local->toDateString().'|'.($local->hour < 12 ? 'am' : 'pm');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $timezone): array
    {
        $local = $this->startsAt->timezone($timezone);

        return [
            'starts_at' => $this->startsAt->utc()->toIso8601String(),
            'date' => $local->toDateString(),
            'day' => $local->format('l j F'),
            'time' => $local->format('H:i'),
            'ends_time' => $this->endsAt->timezone($timezone)->format('H:i'),
            'service_id' => $this->service->id,
            'service_name' => $this->service->name,
            'duration_minutes' => $this->service->duration_minutes,
            'price' => $this->service->price->toArray(),
            'deposit' => $this->service->deposit_amount->toArray(),
            'staff_id' => $this->staff->id,
            'staff_name' => $this->staff->name,
            'staff_ids' => $this->staffIds,
            'subject_id' => $this->subject?->id,
            'subject_name' => $this->subject?->name,
            'reason' => $this->reason,
            'reason_key' => $this->reasonKey->value,
        ];
    }
}
