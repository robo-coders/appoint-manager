<?php

use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;

function asLocalEnvironment(callable $body): void
{
    $original = app()->environment();
    app()->detectEnvironment(fn () => 'local');

    try {
        $body();
    } finally {
        app()->detectEnvironment(fn () => $original);
    }
}

function aTenantToSeed(): Tenant
{
    $tenant = Tenant::factory()->create(['slug' => 'willow', 'name' => 'Willow Street Grooming']);
    User::factory()->create(['tenant_id' => $tenant->id]);

    return $tenant;
}

function seededBookingCount(Tenant $tenant): int
{
    return Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
}

function withNoStripeKeys(): void
{
    config([
        'services.stripe.key' => null,
        'services.stripe.secret' => null,
        'services.stripe.webhook_secret' => null,
    ]);
}

function withStripeTestKeysSet(): void
{
    config([
        'services.stripe.key' => 'pk_test_demo',
        'services.stripe.secret' => 'sk_test_demo',
        'services.stripe.webhook_secret' => 'whsec_demo',
    ]);
}

it('refuses to seed deposits when the stripe keys are missing', function () {
    $tenant = aTenantToSeed();
    withNoStripeKeys();

    asLocalEnvironment(function () use ($tenant) {
        $this->artisan('demo:seed', ['tenant' => 'willow'])
            ->expectsOutputToContain('demo:seed cannot set up deposits, and will not pretend to.')
            ->expectsOutputToContain('STRIPE_KEY')
            ->expectsOutputToContain('STRIPE_SECRET')
            ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET')
            ->assertFailed();

        expect($tenant->fresh()->takesDeposits())->toBeFalse()
            ->and(seededBookingCount($tenant))->toBe(0);
    });
});

it('refuses to seed deposits with keys but no connected account', function () {
    aTenantToSeed();
    withStripeTestKeysSet();

    asLocalEnvironment(function () {
        $this->artisan('demo:seed', ['tenant' => 'willow'])
            ->expectsOutputToContain('--stripe-account=acct_')
            ->assertFailed();
    });
});

it('names the no-deposits escape hatch in the refusal', function () {
    aTenantToSeed();
    withNoStripeKeys();

    asLocalEnvironment(function () {
        $this->artisan('demo:seed', ['tenant' => 'willow'])
            ->expectsOutputToContain('php artisan demo:seed willow --no-deposits')
            ->assertFailed();
    });
});

it('seeds happily with no deposits and no keys, which is what the e2e suite does', function () {
    $tenant = aTenantToSeed();
    withNoStripeKeys();

    asLocalEnvironment(function () use ($tenant) {
        $this->artisan('demo:seed', ['tenant' => 'willow', '--no-deposits' => true])->assertSuccessful();

        expect($tenant->fresh()->takesDeposits())->toBeFalse()
            ->and(seededBookingCount($tenant))->toBeGreaterThan(0);
    });
});

it('seeds deposits against a real connected account when everything is present', function () {
    $tenant = aTenantToSeed();
    withStripeTestKeysSet();

    asLocalEnvironment(function () use ($tenant) {
        $this->artisan('demo:seed', [
            'tenant' => 'willow',
            '--stripe-account' => 'acct_1RealTestAccount',
        ])->assertSuccessful();

        expect($tenant->fresh()->stripe_account_id)->toBe('acct_1RealTestAccount')
            ->and($tenant->fresh()->takesDeposits())->toBeTrue();
    });
});

it('leaves the stripe columns alone under --plan-only', function () {
    $tenant = aTenantToSeed();
    $tenant->forceFill([
        'stripe_account_id' => 'acct_already_here',
        'stripe_onboarding_complete' => true,
    ])->save();
    withNoStripeKeys();

    asLocalEnvironment(function () use ($tenant) {
        $this->artisan('demo:seed', ['tenant' => 'willow', '--plan-only' => true, '--plan' => 'expired'])
            ->assertSuccessful();

        expect($tenant->fresh()->stripe_account_id)->toBe('acct_already_here')
            ->and($tenant->fresh()->isReadOnly())->toBeTrue();
    });
});

it('still rejects a connected account id that is not one', function () {
    aTenantToSeed();
    withStripeTestKeysSet();

    asLocalEnvironment(function () {
        $this->artisan('demo:seed', ['tenant' => 'willow', '--stripe-account' => 'sk_test_oops'])
            ->assertFailed();
    });
});
