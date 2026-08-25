<?php

namespace App\Services\Availability;

use Carbon\CarbonImmutable;

final class TimeWindow
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public function isEmpty(): bool
    {
        return $this->end->lte($this->start);
    }
}
