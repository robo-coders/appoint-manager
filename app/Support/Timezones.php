<?php

namespace App\Support;

class Timezones
{
    public static function identifiers(): array
    {
        $zones = timezone_identifiers_list();
        $preferred = self::forCountry(Currencies::defaultCountry(Currencies::default()));

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

    public static function forCountry(?string $country): string
    {
        $map = self::countryDefaults();
        $code = strtoupper((string) $country);

        return $map[$code] ?? $map['GB'];
    }

    public static function countryDefaults(): array
    {
        return [
            'AT' => 'Europe/Vienna',
            'BE' => 'Europe/Brussels',
            'CY' => 'Asia/Nicosia',
            'DE' => 'Europe/Berlin',
            'EE' => 'Europe/Tallinn',
            'ES' => 'Europe/Madrid',
            'FI' => 'Europe/Helsinki',
            'FR' => 'Europe/Paris',
            'GB' => 'Europe/London',
            'GR' => 'Europe/Athens',
            'IE' => 'Europe/Dublin',
            'IT' => 'Europe/Rome',
            'LT' => 'Europe/Vilnius',
            'LU' => 'Europe/Luxembourg',
            'LV' => 'Europe/Riga',
            'MT' => 'Europe/Malta',
            'NL' => 'Europe/Amsterdam',
            'PT' => 'Europe/Lisbon',
            'SI' => 'Europe/Ljubljana',
            'SK' => 'Europe/Bratislava',
            'US' => 'America/New_York',
        ];
    }
}
