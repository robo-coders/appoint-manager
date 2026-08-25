<?php

namespace App\Support;

final class AvailabilityOverlap
{
    /**
     * Touching endpoints are allowed (09:00–12:00 and 12:00–17:00).
     *
     * @param  list<array{start_time: string, end_time: string}>  $ranges
     */
    public static function rangesOverlap(array $ranges): bool
    {
        $normalized = [];

        foreach ($ranges as $range) {
            $start = self::toMinutes($range['start_time']);
            $end = self::toMinutes($range['end_time']);

            $normalized[] = [$start, $end];
        }

        usort($normalized, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        for ($i = 1, $count = count($normalized); $i < $count; $i++) {
            if ($normalized[$i][0] < $normalized[$i - 1][1]) {
                return true;
            }
        }

        return false;
    }

    public static function toMinutes(string $time): int
    {
        $parts = array_map('intval', explode(':', $time));

        return ($parts[0] ?? 0) * 60 + ($parts[1] ?? 0);
    }
}
