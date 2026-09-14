<?php

use App\Support\Timezones;

it('maps each launch country to a real timezone', function () {
    expect(Timezones::forCountry('GB'))->toBe('Europe/London')
        ->and(Timezones::forCountry('IE'))->toBe('Europe/Dublin')
        ->and(Timezones::forCountry('US'))->toBe('America/New_York')
        ->and(Timezones::forCountry('FR'))->toBe('Europe/Paris');

    foreach (Timezones::countryDefaults() as $zone) {
        expect(in_array($zone, timezone_identifiers_list(), true))->toBeTrue();
    }
});
