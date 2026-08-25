<?php

use App\Support\Money;

it('formats integer pence without using floats', function () {
    expect((new Money(3500))->formatted())->toBe('£35.00')
        ->and((new Money(0))->formatted())->toBe('£0.00')
        ->and((new Money(99))->formatted())->toBe('£0.99')
        ->and((new Money(123456))->formatted())->toBe('£1,234.56')
        ->and((new Money(1000))->toArray())->toBe([
            'amount' => 1000,
            'formatted' => '£10.00',
            'currency' => 'GBP',
        ]);
});

it('rejects a negative amount', function () {
    new Money(-1);
})->throws(InvalidArgumentException::class);
