<?php

namespace App\Services\Availability;

use Carbon\CarbonImmutable;

final readonly class Slot
{
    /**
     * @param  list<int>  $staffIds
     */
    public function __construct(
        public CarbonImmutable $startsAt,
        public array $staffIds,
    ) {}

    /**
     * @return array{starts_at: string, staff_ids: list<int>}
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->utc()->toIso8601String(),
            'staff_ids' => $this->staffIds,
        ];
    }
}
