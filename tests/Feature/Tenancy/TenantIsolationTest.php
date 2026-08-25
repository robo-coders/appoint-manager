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

it('returns no tenant-owned rows over http when tenant context is missing', function () {
    Service::factory()->create();

    expect(Service::query()->count())->toBe(0);
});
