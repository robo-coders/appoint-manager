<?php

use App\BetaSandbox\StripeTestMode;
use App\Exceptions\PaymentsNotConfiguredException;
use App\Models\Tenant;
use App\Services\Stripe\StripeConnectGateway;

const LIVE_KEY = 'sk_live_notarealkey';
const TEST_KEY = 'sk_test_notarealkey';

beforeEach(function () {
    config([
        'services.stripe.secret' => LIVE_KEY,
        'services.stripe.test_secret' => null,
    ]);
});

it('uses the platform key for a salon that is not in the beta', function () {
    config(['services.stripe.test_secret' => TEST_KEY]);

    $tenant = Tenant::factory()->create();

    expect(StripeTestMode::secretFor($tenant->fresh()))->toBe(LIVE_KEY);
});

it('uses the test key for a beta salon even when the platform is live', function () {
    config(['services.stripe.test_secret' => TEST_KEY]);

    $tenant = Tenant::factory()->create(['is_beta' => true]);

    expect(StripeTestMode::secretFor($tenant->fresh()))->toBe(TEST_KEY);
});

it('accepts a restricted test key', function () {
    config(['services.stripe.test_secret' => 'rk_test_restricted']);

    $tenant = Tenant::factory()->create(['is_beta' => true]);

    expect(StripeTestMode::secretFor($tenant->fresh()))->toBe('rk_test_restricted');
});

it('reuses the platform key for a beta salon when the platform is already in test mode', function () {
    config(['services.stripe.secret' => TEST_KEY]);

    $tenant = Tenant::factory()->create(['is_beta' => true]);

    expect(StripeTestMode::secretFor($tenant->fresh()))->toBe(TEST_KEY);
});

it('refuses outright rather than falling back to the live key', function () {
    $tenant = Tenant::factory()->create(['is_beta' => true]);

    expect(fn () => StripeTestMode::secretFor($tenant->fresh()))
        ->toThrow(PaymentsNotConfiguredException::class, 'STRIPE_TEST_SECRET');
});

it('refuses a test key that is not recognisably a test key', function () {
    config(['services.stripe.test_secret' => 'sk_live_pretending']);

    $tenant = Tenant::factory()->create(['is_beta' => true]);

    expect(fn () => StripeTestMode::secretFor($tenant->fresh()))
        ->toThrow(PaymentsNotConfiguredException::class);
});

it('leaves the platform\'s own tenant-less calls alone', function () {
    config(['services.stripe.test_secret' => TEST_KEY]);

    expect(StripeTestMode::secretFor(null))->toBe(LIVE_KEY);
});

it('finds the beta salon behind a connected account id', function () {
    config(['services.stripe.test_secret' => TEST_KEY]);

    Tenant::factory()->create(['is_beta' => true, 'stripe_account_id' => 'acct_beta']);
    Tenant::factory()->create(['is_beta' => false, 'stripe_account_id' => 'acct_real']);

    expect(StripeTestMode::secretForAccount('acct_beta'))->toBe(TEST_KEY);
    expect(StripeTestMode::secretForAccount('acct_real'))->toBe(LIVE_KEY);
    expect(StripeTestMode::secretForAccount(null))->toBe(LIVE_KEY);
});

it('builds a beta salon\'s payment-intent client on the test key', function () {
    config(['services.stripe.test_secret' => TEST_KEY]);

    $tenant = Tenant::factory()->create(['is_beta' => true])->fresh();

    expect(stripeClientKeyFor($tenant))->toBe(TEST_KEY);
});

it('builds an ordinary salon\'s payment-intent client on the live key', function () {
    config(['services.stripe.test_secret' => TEST_KEY]);

    $tenant = Tenant::factory()->create()->fresh();

    expect(stripeClientKeyFor($tenant))->toBe(LIVE_KEY);
});

function stripeClientKeyFor(Tenant $tenant): string
{
    $client = (new ReflectionMethod(StripeConnectGateway::class, 'client'))
        ->invoke(new StripeConnectGateway, $tenant);

    return (string) $client->getApiKey();
}
