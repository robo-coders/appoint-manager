<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DemoDataSeeder;

/**
 * What a seeded demo tenant is *for*, asserted.
 *
 * `DemoDataSeeder` fills a salon with a realistic week, and twice now the state
 * around that data has been wrong in a way that made the demo useless while the
 * data itself was fine. Both are fixed; these are what stop them coming back.
 *
 * The seeder runs in `testing` deliberately — see `guardEnvironment()` — and
 * `RefreshDatabase` throws the result away, so this asserts against exactly the
 * same code path a developer runs on their own machine.
 */

/**
 * A tenant the seeder can fill: it fills one that already exists, and the owner
 * has to be there before it runs.
 *
 * The billing columns are cleared back to what the *migration* leaves, because
 * that is the state the bug lives in. `TenantFactory` helpfully sets a 30-day
 * trial, which is exactly the fix under test — a fixture that arrives already
 * repaired cannot tell you whether the seeder repairs anything. A tenant made
 * by `firstOrCreate` in a tinker one-liner, which is how both `demo:seed` and
 * `scripts/e2e-setup.sh` get theirs, has these defaults and nothing else.
 */
function aSeedableTenant(): Tenant
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London', 'name' => 'Willow Street Grooming']);
    $tenant->forceFill(['subscription_status' => 'trial', 'trial_ends_at' => null])->save();
    User::factory()->create(['tenant_id' => $tenant->id]);

    return $tenant->refresh();
}

/*
 * `tenants.subscription_status` defaults to 'trial' and `trial_ends_at` defaults
 * to NULL, and `hasAdminWriteAccess()` reads the *date* rather than the status —
 * so a tenant created any way other than through the billing flow had no write
 * access, and every screen in the admin app rendered behind "Admin is read-only
 * until billing is up to date". It was being fixed by hand, per machine, by
 * whoever hit it.
 */
it('leaves a demo tenant writable rather than read-only behind a billing banner', function () {
    $tenant = aSeedableTenant();
    expect($tenant->isReadOnly())->toBeTrue('a bare tenant starts read-only — that is the bug this guards');

    (new DemoDataSeeder)->forTenant($tenant);
    app(TenantContext::class)->clear();

    $tenant->refresh();

    expect($tenant->trial_ends_at)->not->toBeNull();
    expect($tenant->onTrial())->toBeTrue();
    expect($tenant->isReadOnly())->toBeFalse();
});

/*
 * Deposit capture is what this product sells, and the demo tenant had no
 * connected account — so `takesDeposits()` was false, the public booking page
 * fell back to "£35.00, pay on the day", and the feature was invisible on the
 * one page a salon owner is shown.
 *
 * Two tenant columns, not a question about which gateway is bound: AUDIT C1 is
 * untouched by this and `FakeStripeGateway` stays reachable in `testing` only.
 */
it('presents a demo tenant as Stripe-connected so the booking page shows deposits', function () {
    $tenant = aSeedableTenant();

    (new DemoDataSeeder)->forTenant($tenant);
    app(TenantContext::class)->clear();

    expect($tenant->refresh()->takesDeposits())->toBeTrue();
});

/*
 * And the opt-out, which `scripts/e2e-setup.sh` depends on: that suite books
 * through the public page against obvious fake Stripe keys, so a tenant asking
 * for a deposit there returns 503 where `slot-race.spec.ts` asserts 201.
 */
it('can be seeded with no Stripe account, for a suite that must not reach one', function () {
    $tenant = aSeedableTenant();

    (new DemoDataSeeder)->forTenant($tenant, deposits: false);
    app(TenantContext::class)->clear();

    $tenant->refresh();

    expect($tenant->takesDeposits())->toBeFalse();
    expect($tenant->stripe_account_id)->toBeNull();
    // Still writable: the two are independent, and an e2e run needs write access.
    expect($tenant->isReadOnly())->toBeFalse();
});

/*
 * `demo:seed --plan=` is applied after the fill, so it still has the last word.
 * That is what keeps `--plan=expired` able to show the read-only state on
 * purpose rather than being overwritten by the trial the fill sets.
 */
it('lets an explicit billing state override the trial the fill sets', function () {
    $tenant = aSeedableTenant();

    (new DemoDataSeeder)->forTenant($tenant);
    app(TenantContext::class)->clear();

    DemoDataSeeder::billing($tenant, 'expired');

    expect($tenant->refresh()->isReadOnly())->toBeTrue();
});
