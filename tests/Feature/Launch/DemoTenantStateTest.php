<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DemoDataSeeder;

function aSeedableTenant(): Tenant
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London', 'name' => 'Willow Street Grooming']);
    $tenant->forceFill(['subscription_status' => 'trial', 'trial_ends_at' => null])->save();
    User::factory()->create(['tenant_id' => $tenant->id]);

    return $tenant->refresh();
}

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

it('presents a demo tenant as Stripe-connected so the booking page shows deposits', function () {
    $tenant = aSeedableTenant();

    (new DemoDataSeeder)->forTenant($tenant);
    app(TenantContext::class)->clear();

    expect($tenant->refresh()->takesDeposits())->toBeTrue();
});

it('can be seeded with no Stripe account, for a suite that must not reach one', function () {
    $tenant = aSeedableTenant();

    (new DemoDataSeeder)->forTenant($tenant, deposits: false);
    app(TenantContext::class)->clear();

    $tenant->refresh();

    expect($tenant->takesDeposits())->toBeFalse();
    expect($tenant->stripe_account_id)->toBeNull();
    expect($tenant->isReadOnly())->toBeFalse();
});

it('lets an explicit billing state override the trial the fill sets', function () {
    $tenant = aSeedableTenant();

    (new DemoDataSeeder)->forTenant($tenant);
    app(TenantContext::class)->clear();

    DemoDataSeeder::billing($tenant, 'expired');

    expect($tenant->refresh()->isReadOnly())->toBeTrue();
});
