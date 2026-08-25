<?php

namespace App\Http\Requests\Concerns;

use App\Support\AvailabilityOverlap;
use Illuminate\Validation\Validator;

trait ValidatesAvailabilityRanges
{
    /**
     * @param  list<array{weekday?: mixed, start_time?: mixed, end_time?: mixed}>  $ranges
     */
    protected function validateNoOverlaps(Validator $validator, array $ranges, string $attribute = 'ranges'): void
    {
        $grouped = [];

        foreach ($ranges as $range) {
            if (! isset($range['weekday'], $range['start_time'], $range['end_time'])) {
                continue;
            }

            $key = (string) $range['weekday'];
            $grouped[$key][] = [
                'start_time' => (string) $range['start_time'],
                'end_time' => (string) $range['end_time'],
            ];
        }

        foreach ($grouped as $weekday => $dayRanges) {
            if (AvailabilityOverlap::rangesOverlap($dayRanges)) {
                $validator->errors()->add(
                    $attribute,
                    'Time ranges cannot overlap for the same person on the same day.',
                );

                return;
            }
        }
    }
}
