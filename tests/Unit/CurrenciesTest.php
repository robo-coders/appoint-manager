<?php

use App\Support\Currencies;
use App\Support\Money;

it('formats each supported currency with its own symbol', function () {
    expect((new Money(3500, 'GBP'))->formatted())->toBe('£35.00')
        ->and((new Money(3500, 'EUR'))->formatted())->toBe('€35.00')
        ->and((new Money(3500, 'USD'))->formatted())->toBe('$35.00')
        ->and((new Money(123456, 'EUR'))->formatted())->toBe('€1,234.56');
});

it('falls back to the code for a currency it does not know', function () {
    expect((new Money(3500, 'JPY'))->formatted())->toBe('JPY 35.00');
});

it('carries the symbol through the array the frontend receives', function () {
    expect((new Money(2000, 'EUR'))->toArray())->toBe([
        'amount' => 2000,
        'formatted' => '€20.00',
        'currency' => 'EUR',
    ]);
});

it('offers at least pounds, euros and dollars at launch', function () {
    expect(Currencies::codes())->toContain('GBP', 'EUR', 'USD');
});

it('only accepts a country that settles in the chosen currency', function () {
    expect(Currencies::supportsCountry('EUR', 'IE'))->toBeTrue()
        ->and(Currencies::supportsCountry('EUR', 'GB'))->toBeFalse()
        ->and(Currencies::supportsCountry('GBP', 'GB'))->toBeTrue()
        ->and(Currencies::supportsCountry('USD', 'IE'))->toBeFalse();
});

it('names a default country for every supported currency', function () {
    foreach (Currencies::codes() as $code) {
        expect(Currencies::supportsCountry($code, Currencies::defaultCountry($code)))->toBeTrue();
    }
});

it('reads a new currency from config without a schema change', function () {
    config()->set('currencies.supported.CHF', [
        'symbol' => 'CHF ',
        'label' => 'Swiss franc',
        'countries' => ['CH' => 'Switzerland'],
    ]);

    expect(Currencies::codes())->toContain('CHF')
        ->and((new Money(3500, 'CHF'))->formatted())->toBe('CHF 35.00')
        ->and(Currencies::defaultCountry('CHF'))->toBe('CH');
});
