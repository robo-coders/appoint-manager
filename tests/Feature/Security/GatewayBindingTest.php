<?php

use App\Models\StripeEvent;
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
    config(['services.stripe.secret' => null]);

    expect(fn () => rebind(StripeGateway::class))
        ->toThrow(RuntimeException::class, 'STRIPE_SECRET');
});

it('never falls back to the fake stripe gateway outside testing', function () {
    app()['env'] = 'production';
    config([
        'services.stripe.secret' => 'sk_live_x',
        'services.stripe.webhook_secret' => 'whsec_x',
    ]);

    expect(rebind(StripeGateway::class))->toBeInstanceOf(StripeConnectGateway::class);
});

it('refuses to boot when the webhook secret is missing, even with a live key', function () {
    app()['env'] = 'production';
    config([
        'services.stripe.secret' => 'sk_live_x',
        'services.stripe.webhook_secret' => null,
    ]);

    expect(fn () => rebind(StripeGateway::class))->toThrow(RuntimeException::class, 'STRIPE_WEBHOOK_SECRET');
});

it('refuses to boot a billing gateway in production when billing is not configured', function () {
    app()['env'] = 'production';
    config([
        'services.stripe.secret' => 'sk_live_x',
        'billing.monthly_price_id' => null,
    ]);

    expect(fn () => rebind(BillingGateway::class))->toThrow(RuntimeException::class);
});

/*
 * AUDIT C1. The hole was `STRIPE_FAKE`: an opt-in that bound the fake gateway in
 * any environment that was not literally named `production`. A staging box, or a
 * local box somebody pointed a real webhook at, would then accept the fake's
 * literal `t=1,v1=test` signature and confirm whatever booking id an
 * unauthenticated request named.
 *
 * These assert the hole is shut, not that the happy path still works: every
 * environment other than `testing` must be unable to reach the fake at all.
 */
it('cannot resolve the fake stripe gateway in any environment but testing', function () {
    foreach (['local', 'staging', 'production', 'anything-else'] as $environment) {
        app()['env'] = $environment;
        config(['services.stripe.secret' => null]);

        expect(fn () => rebind(StripeGateway::class))
            ->toThrow(RuntimeException::class, 'STRIPE_SECRET', "environment [{$environment}] reached the fake gateway");
    }
});

it('cannot resolve the fake billing gateway in any environment but testing', function () {
    foreach (['local', 'staging', 'production'] as $environment) {
        app()['env'] = $environment;
        config(['services.stripe.secret' => null]);

        expect(fn () => rebind(BillingGateway::class))->toThrow(RuntimeException::class);
    }
});

it('has no STRIPE_FAKE escape hatch left to set', function () {
    expect(config()->has('services.stripe.fake'))->toBeFalse();
});

/*
 * The binding is one lock; this is the second. The class is `new`-able, so a
 * seeder or a helper could construct one directly — and the thing it would then
 * be willing to do is accept a forged webhook signature.
 */
it('will not let the fake stripe gateway construct an event outside testing', function () {
    foreach (['local', 'staging', 'production'] as $environment) {
        app()['env'] = $environment;

        expect(fn () => (new FakeStripeGateway)->constructEvent('{"id":"evt","type":"x"}', 't=1,v1=test'))
            ->toThrow(RuntimeException::class, 'test suite only');
    }
});

it('will not let the fake billing gateway construct an event outside testing', function () {
    foreach (['local', 'staging', 'production'] as $environment) {
        app()['env'] = $environment;

        expect(fn () => (new FakeBillingGateway)->constructEvent('{"id":"evt","type":"x"}', 'test_billing'))
            ->toThrow(RuntimeException::class, 'test suite only');
    }
});

/*
 * The webhook route is the thing the fake would have exposed. A signature the
 * real gateway rejects must be a 400 and must write nothing, whatever the
 * payload claims.
 */
it('rejects a forged webhook signature at the door', function () {
    app()['env'] = 'testing';

    $response = $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'not-the-signature',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode([
        'id' => 'evt_forged',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['metadata' => ['booking_id' => '1']]],
    ]));

    $response->assertStatus(400);
    expect(StripeEvent::query()->count())->toBe(0);
});
