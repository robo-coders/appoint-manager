<?php

namespace App\Support;

final class Currencies
{
    public static function default(): string
    {
        $code = strtoupper((string) config('currencies.default', 'GBP'));

        return self::supports($code) ? $code : array_key_first(self::all());
    }

    /** @return array<string, array{symbol: string, label: string, countries: array<string, string>}> */
    public static function all(): array
    {
        /** @var array<string, array{symbol: string, label: string, countries: array<string, string>}> $supported */
        $supported = (array) config('currencies.supported', []);

        return $supported;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function supports(?string $code): bool
    {
        return $code !== null && array_key_exists(strtoupper($code), self::all());
    }

    public static function symbol(string $code): ?string
    {
        return self::all()[strtoupper($code)]['symbol'] ?? null;
    }

    public static function label(string $code): string
    {
        return self::all()[strtoupper($code)]['label'] ?? strtoupper($code);
    }

    /** @return list<string> */
    public static function countryCodes(string $code): array
    {
        return array_keys(self::all()[strtoupper($code)]['countries'] ?? []);
    }

    public static function supportsCountry(string $code, ?string $country): bool
    {
        return $country !== null && in_array(strtoupper($country), self::countryCodes($code), true);
    }

    public static function defaultCountry(string $code): string
    {
        return self::countryCodes($code)[0] ?? 'GB';
    }

    /** @return list<array{value: string, label: string, symbol: string}> */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $code => $currency) {
            $options[] = [
                'value' => $code,
                'label' => $currency['label'],
                'symbol' => $currency['symbol'],
            ];
        }

        return $options;
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function countryOptions(): array
    {
        $options = [];

        foreach (self::all() as $code => $currency) {
            $rows = [];

            foreach ($currency['countries'] as $country => $name) {
                $rows[] = ['value' => $country, 'label' => $name];
            }

            $options[$code] = $rows;
        }

        return $options;
    }
}
