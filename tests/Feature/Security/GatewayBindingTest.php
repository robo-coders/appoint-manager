<?php

use App\Services\Billing\BillingGateway;
use App\Services\Billing\FakeBillingGateway;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\StripeConnectGateway;
use App\Services\Stripe\StripeGateway;

/** Re-resolve a singleton after changing the environment it is bound against. */
function rebind(string $abstract): mixed
{
    app()->forgetInstance($abstract);

    return app($abstract);
}

it('binds the fake stripe gateway under testing', function () {
    expect(app(StripeGateway::class))->toBeInstanceOf(FakeStripeGateway::class);
});

it('refuses to boot a stripe gateway in production when the secret is missing', function () {
    app()['env'] = 'production';
    config(['services.stripe.secret' => null, 'services.stripe.fake' => false]);

    expect(fn () => rebind(StripeGateway::class))
        ->toThrow(RuntimeException::class, 'STRIPE_SECRET');
});

it('never falls back to the fake stripe gateway outside testing', function () {
    app()['env'] = 'production';
    config([
        'services.stripe.secret' => 'sk_live_x',
        'services.stripe.webhook_secret' => 'whsec_x',
        'services.stripe.fake' => false,
    ]);

    expect(rebind(StripeGateway::class))->toBeInstanceOf(StripeConnectGateway::class);
});

it('refuses to boot when the webhook secret is missing, even with a live key', function () {
    app()['env'] = 'production';
    config([
        'services.stripe.secret' => 'sk_live_x',
        'services.stripe.webhook_secret' => null,
        'services.stripe.fake' => false,
    ]);

    expect(fn () => rebind(StripeGateway::class))->toThrow(RuntimeException::class, 'STRIPE_WEBHOOK_SECRET');
});

it('refuses to boot a billing gateway in production when billing is not configured', function () {
    app()['env'] = 'production';
    config([
        'services.stripe.secret' => 'sk_live_x',
        'billing.monthly_price_id' => null,
        'services.stripe.fake' => false,
    ]);

    expect(fn () => rebind(BillingGateway::class))->toThrow(RuntimeException::class);
});

it('allows an explicit fake opt-in outside testing for local development', function () {
    app()['env'] = 'local';
    config(['services.stripe.secret' => null, 'services.stripe.fake' => true]);

    expect(rebind(StripeGateway::class))->toBeInstanceOf(FakeStripeGateway::class);
});

it('will not let the fake stripe gateway construct an event in production', function () {
    app()['env'] = 'production';

    expect(fn () => (new FakeStripeGateway)->constructEvent('{"id":"evt","type":"x"}', 't=1,v1=test'))
        ->toThrow(RuntimeException::class);
});

it('will not let the fake billing gateway construct an event in production', function () {
    app()['env'] = 'production';

    expect(fn () => (new FakeBillingGateway)->constructEvent('{"id":"evt","type":"x"}', 'test_billing'))
        ->toThrow(RuntimeException::class);
});
