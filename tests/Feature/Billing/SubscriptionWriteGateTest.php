<?php

use App\Models\Tenant;
use App\Models\User;

function aLockedTenant(): Tenant
{
    $tenant = Tenant::factory()->create([
        'subscription_status' => 'trial',
        'trial_ends_at' => now()->subDay(),
    ]);

    expect($tenant->isReadOnly())->toBeTrue();

    return $tenant;
}

/** @return array<string, mixed> */
function aSettingsPatch(Tenant $tenant): array
{
    return [
        'name' => $tenant->name.' renamed',
        'timezone' => 'Europe/London',
    ];
}

it('locks an owner out of writing when billing has lapsed', function () {
    $tenant = aLockedTenant();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => false]);

    $this->actingAs($owner)
        ->from(route('settings.edit'))
        ->patch(route('settings.update'), aSettingsPatch($tenant))
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHas('toast', fn (string $toast) => str_contains($toast, 'read-only'));

    expect($tenant->fresh()->name)->toBe($tenant->name);
});

it('lets a super admin who is not impersonating write through the lock', function () {
    $tenant = aLockedTenant();
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => true]);

    $this->actingAs($admin)
        ->patch(route('settings.update'), aSettingsPatch($tenant))
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHasNoErrors();

    expect($tenant->fresh()->name)->toBe($tenant->name.' renamed');
});

it('keeps a super admin inside the lock while they are impersonating', function () {
    $tenant = aLockedTenant();
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => true]);

    $this->actingAs($admin)
        ->withSession(['impersonator_id' => $admin->id])
        ->from(route('settings.edit'))
        ->patch(route('settings.update'), aSettingsPatch($tenant))
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHas('toast', fn (string $toast) => str_contains($toast, 'read-only'));

    expect($tenant->fresh()->name)->toBe($tenant->name);
});

it('leaves the way out of an impersonated session open', function () {
    $tenant = aLockedTenant();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => false]);
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);

    $this->actingAs($owner)
        ->withSession(['impersonator_id' => $admin->id])
        ->withHeader('X-Inertia', 'true')
        ->post(route('impersonation.stop'))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

it('does not gate a read on the billing settings screen', function () {
    $tenant = aLockedTenant();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => false]);

    $this->actingAs($owner)->get(route('settings.billing'))->assertOk();
});
