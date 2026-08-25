<?php

namespace App\Support;

class Timezones
{
    /**
     * @return list<string>
     */
    public static function identifiers(): array
    {
        $zones = timezone_identifiers_list();
        $preferred = 'Europe/London';

        usort($zones, function (string $a, string $b) use ($preferred): int {
            if ($a === $preferred) {
                return -1;
            }
            if ($b === $preferred) {
                return 1;
            }

            return $a <=> $b;
        });

        return array_values($zones);
    }
}
