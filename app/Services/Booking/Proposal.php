<?php

namespace App\Services\Booking;

use App\Models\Service;
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class Proposal
{
    /** @param  list<int>  $staffIds  Everyone free at this instant. `staff` is the */
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

    public function bucket(string $timezone): string
    {
        $local = $this->startsAt->timezone($timezone);

        return $local->toDateString().'|'.($local->hour < 12 ? 'am' : 'pm');
    }

    /** @return array<string, mixed> */
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
