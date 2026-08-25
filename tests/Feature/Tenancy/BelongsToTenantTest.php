<?php

use App\Exceptions\MissingTenantContextException;
use App\Exceptions\TenantMismatchException;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\TenantContext;

it('fills tenant_id from the current context on create', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    $service = Service::query()->create([
        'name' => 'Bath',
        'duration_minutes' => 45,
        'price' => 2500,
        'deposit_amount' => 1000,
    ]);

    expect($service->tenant_id)->toBe($tenant->id);
});

it('throws when a tenant-owned model is created with no context and no tenant_id', function () {
    Service::query()->create([
        'name' => 'Bath',
        'duration_minutes' => 45,
        'price' => 2500,
        'deposit_amount' => 1000,
    ]);
})->throws(MissingTenantContextException::class);

it('throws when an explicit tenant_id does not match the current context', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    app(TenantContext::class)->set($tenantA);

    $service = new Service;
    $service->forceFill([
        'tenant_id' => $tenantB->id,
        'name' => 'Bath',
        'duration_minutes' => 45,
        'price' => 2500,
        'deposit_amount' => 1000,
    ])->save();
})->throws(TenantMismatchException::class);
