<?php

namespace App\BetaSandbox;

use App\Exceptions\PaymentsNotConfiguredException;
use App\Models\Tenant;

final class StripeTestMode
{
    private const TEST_PREFIXES = ['sk_test_', 'rk_test_'];

    public static function secretFor(?Tenant $tenant): string
    {
        $configured = (string) config('services.stripe.secret');

        if (! BetaSandbox::enabled($tenant)) {
            return $configured;
        }

        $test = (string) config('services.stripe.test_secret');

        if ($test !== '' && self::isTestKey($test)) {
            return $test;
        }

        if (self::isTestKey($configured)) {
            return $configured;
        }

        throw PaymentsNotConfiguredException::missing(
            'STRIPE_TEST_SECRET',
            'Refusing to talk to Stripe for a beta tenant with a live key. Beta salons are '
            .'sandboxes: they may only ever reach Stripe test mode.'
        );
    }

    public static function secretForAccount(?string $accountId): string
    {
        if ($accountId === null || $accountId === '') {
            return self::secretFor(null);
        }

        return self::secretFor(
            Tenant::query()->where('stripe_account_id', $accountId)->first(),
        );
    }

    private static function isTestKey(string $key): bool
    {
        foreach (self::TEST_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
