<?php

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;

/** A super admin whose own tenant has not finished onboarding. */
function superAdmin(array $tenantOverrides = []): User
{
    $tenant = Tenant::factory()->create(array_merge([
        'onboarding_completed_at' => null,
    ], $tenantOverrides));

    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'is_super_admin' => true,
    ]);
}

it('lets a super admin reach the console even when their tenant has not onboarded', function () {
    $this->actingAs(superAdmin())->get('/admin')->assertOk();
});

it('lets a super admin reach the console with no tenant at all', function () {
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('sends a super admin to the console after logging in, not to onboarding', function () {
    $admin = superAdmin();
    $admin->forceFill(['password' => bcrypt('password')])->save();

    $this->post(route('login'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect(route('super-admin.index'));
});

it('still sends an ordinary owner to onboarding after logging in', function () {
    $tenant = Tenant::factory()->create(['onboarding_completed_at' => null]);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'password' => bcrypt('password'),
    ]);

    $this->post(route('login'), ['email' => $owner->email, 'password' => 'password'])
        ->assertRedirect(home_route());

    $this->get(home_route())->assertRedirect(route('onboarding.show'));
});

it('keeps the console closed to a non-super-admin', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => false]);

    $this->actingAs($user)->get('/admin')->assertForbidden();
});
