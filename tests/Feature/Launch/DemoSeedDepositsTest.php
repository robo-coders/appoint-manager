<?php

use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;

/**
 * `demo:seed` refuses to set up deposits it cannot complete.
 *
 * The command used to seed a placeholder connected account and print a
 * paragraph saying that paying would not actually work. The booking page then
 * showed the deposit line, Reserve took the deposit branch, and Stripe rejected
 * the account id — a 503 at the last step of the one flow the demo exists to
 * show. It read as a broken product rather than as an unset variable, and it
 * was only discovered in the browser, after the seed had already run.
 *
 * It is a precondition now. Nothing is written until the keys and the account
 * are both there.
 */

/** The command is local-only, and this is a test. */
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

/**
 * Unscoped on purpose: the command clears the tenant context when it finishes,
 * and `Booking` fails closed without one, so `$tenant->bookings()` counts zero
 * whether the seed ran or not.
 */
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

        // And it stopped before writing anything, rather than seeding a salon
        // and then complaining.
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

/*
 * The message has to be actionable, not just correct. Whoever hits this is
 * being told to go and do something, so the way out has to be in the same
 * output as the refusal.
 */
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

/*
 * `--plan-only` says it changes the billing state and nothing else, and now it
 * does. It used to overwrite the Stripe columns on its way past, which meant
 * looking at the read-only banner silently reset the demo's payment setup — and
 * with the check above it would have refused to run at all without keys, for a
 * flag that has nothing to do with payments.
 */
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
