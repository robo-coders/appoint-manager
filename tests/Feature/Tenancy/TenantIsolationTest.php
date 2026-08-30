<?php

use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

it('cannot leak services across tenants', function () {
    $tenantA = Tenant::factory()->create(['name' => 'Salon A']);
    $tenantB = Tenant::factory()->create(['name' => 'Salon B']);

    $ownerA = User::factory()->for($tenantA)->owner()->create();
    User::factory()->for($tenantB)->owner()->create();

    $serviceA = Service::factory()->for($tenantA)->create(['name' => 'A cut']);
    $serviceB = Service::factory()->for($tenantB)->create(['name' => 'B cut']);

    app(TenantContext::class)->set($tenantA);

    expect(Service::query()->pluck('id')->all())->toBe([$serviceA->id])
        ->and(Service::query()->find($serviceB->id))->toBeNull();

    actingAsTenant($ownerA)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Services/Index')
            ->has('services', 1)
            ->where('services.0.id', $serviceA->id));

    $this->get(route('services.show', $serviceA))->assertOk();

    $this->get(route('services.show', $serviceB))->assertNotFound();
});

it('returns no tenant-owned rows when tenant context is missing, even outside the test environment', function () {
    Service::factory()->create();
    app(TenantContext::class)->clear();

    /*
     * AUDIT.md said this passed only because `runningUnitTests()` was true.
     * That exemption is gone — `TenantScope` fail-closes everywhere, and
     * `ScopeFailClosedTest` already proves it by flipping `env` to `local`.
     * This test does the same flip so a regression that re-opens the console
     * exemption cannot hide behind APP_ENV=testing. It cannot fail-first on
     * current code: the code is already right.
     */
    $previous = app()['env'];
    app()['env'] = 'local';

    try {
        expect(app()->runningUnitTests())->toBeFalse()
            ->and(Service::query()->count())->toBe(0);
    } finally {
        app()['env'] = $previous;
    }
});
