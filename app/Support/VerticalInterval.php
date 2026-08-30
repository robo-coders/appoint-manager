<?php

namespace App\Support;

/**
 * Turns a vertical's `{value, unit}` interval into days.
 *
 * Units live in `config/verticals.php` so a dentist tenant can say months and
 * a groomer can say weeks without a code change. Storage is always days.
 */
final class VerticalInterval
{
    /**
     * @param  array{value?: int|string, unit?: string}|null  $interval
     */
    public static function toDays(?array $interval): ?int
    {
        if ($interval === null) {
            return null;
        }

        $value = (int) ($interval['value'] ?? 0);

        if ($value < 1) {
            return null;
        }

        return match ($interval['unit'] ?? 'days') {
            'weeks' => $value * 7,
            'months' => $value * 30,
            default => $value,
        };
    }

    public static function daysForNamedService(string $verticalKey, string $name): ?int
    {
        foreach (config('verticals.'.$verticalKey.'.default_services', []) as $service) {
            if (($service['name'] ?? '') === $name) {
                return self::toDays($service['rebook_interval'] ?? null);
            }
        }

        return null;
    }

    public static function phrase(int $days): string
    {
        if ($days % 30 === 0 && $days >= 30) {
            $months = intdiv($days, 30);

            return $months === 1 ? 'every month' : 'every '.$months.' months';
        }

        if ($days % 7 === 0) {
            $weeks = intdiv($days, 7);

            return $weeks === 1 ? 'every week' : 'every '.$weeks.' weeks';
        }

        return $days === 1 ? 'every day' : 'every '.$days.' days';
    }
}
