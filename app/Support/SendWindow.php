<?php

namespace App\Support;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * The hours a rebooking chase may go out in.
 *
 * Evaluated in the tenant's own timezone, which is the only timezone that
 * means anything here: a groomer in Sydney does not want her clients texted at
 * whatever hour our server thinks is nine in the morning. `tenants.timezone`
 * already exists and every other time in this product is rendered through it.
 *
 * A subject who becomes due outside the window is not dropped. Nothing is
 * claimed and nothing is sent, so the next run inside the window sends it —
 * which is why `rebooking:send` is scheduled hourly rather than daily.
 */
final class SendWindow
{
    /**
     * @return array{start: string, end: string, days: list<int>}
     */
    public static function forTenant(Tenant $tenant): array
    {
        $default = (array) config('rebooking.send_window');
        $override = (array) data_get($tenant->settings, 'rebooking.send_window', []);

        $days = array_values(array_filter(array_map(
            'intval',
            (array) ($override['days'] ?? $default['days'] ?? [1, 2, 3, 4, 5]),
        ), fn (int $day) => $day >= 1 && $day <= 7));

        return [
            'start' => (string) ($override['start'] ?? $default['start'] ?? '09:00'),
            'end' => (string) ($override['end'] ?? $default['end'] ?? '18:00'),
            'days' => $days === [] ? [1, 2, 3, 4, 5] : $days,
        ];
    }

    /**
     * Is it a reasonable hour, where this salon is?
     */
    public static function isOpen(Tenant $tenant, ?CarbonImmutable $at = null): bool
    {
        $window = self::forTenant($tenant);
        $local = ($at ?? CarbonImmutable::now())->setTimezone($tenant->timezone);

        if (! in_array($local->dayOfWeekIso, $window['days'], true)) {
            return false;
        }

        $minutes = $local->hour * 60 + $local->minute;

        return $minutes >= self::minutes($window['start'])
            && $minutes < self::minutes($window['end']);
    }

    /**
     * Human copy for the operator, in her own timezone, so the screen and the
     * behaviour cannot disagree.
     */
    public static function describe(Tenant $tenant): string
    {
        $window = self::forTenant($tenant);
        $days = $window['days'];
        sort($days);

        $names = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $contiguous = $days === range((int) min($days), (int) max($days));

        $when = match (true) {
            $days === [1, 2, 3, 4, 5] => 'weekdays',
            $days === [1, 2, 3, 4, 5, 6, 7] => 'every day',
            count($days) === 1 => $names[$days[0]].'s',
            $contiguous => $names[(int) min($days)].' to '.$names[(int) max($days)],
            default => implode(', ', array_map(fn (int $day) => $names[$day], $days)),
        };

        return $window['start'].' to '.$window['end'].', '.$when;
    }

    private static function minutes(string $time): int
    {
        [$hours, $mins] = array_pad(array_map('intval', explode(':', $time, 2)), 2, 0);

        return $hours * 60 + $mins;
    }
}
