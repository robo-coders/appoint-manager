<?php

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;

/**
 * The seeder creates an account with a published password, so the guard that
 * keeps it out of every environment but local is the part worth testing.
 */
it('refuses to run outside local', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    expect(fn () => (new SuperAdminSeeder)->run())
        ->toThrow(RuntimeException::class, 'refuses to run');

    expect(User::withoutGlobalScopes()->where('email', 'admin@gmail.com')->exists())->toBeFalse();
})->with(['production', 'staging', 'testing']);

it('creates a super admin that belongs to no tenant', function () {
    app()->detectEnvironment(fn () => 'local');

    (new SuperAdminSeeder)->run();

    $admin = User::withoutGlobalScopes()->where('email', 'admin@gmail.com')->firstOrFail();

    expect($admin->is_super_admin)->toBeTrue()
        ->and($admin->tenant_id)->toBeNull()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('admin@1234', $admin->password))->toBeTrue();
});

it('is safe to run twice', function () {
    app()->detectEnvironment(fn () => 'local');

    (new SuperAdminSeeder)->run();
    (new SuperAdminSeeder)->run();

    expect(User::withoutGlobalScopes()->where('email', 'admin@gmail.com')->count())->toBe(1);
});

/**
 * The case that actually blocked getting into the console: the email was
 * already held by a tenant owner made through the ordinary signup form. That
 * account authenticates fine and is then rejected by the console for not being
 * a super admin, which is indistinguishable from a wrong password.
 */
it('converts an existing tenant owner rather than failing on the unique email', function () {
    app()->detectEnvironment(fn () => 'local');

    $tenant = Tenant::factory()->create(['name' => 'Groom']);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'admin@gmail.com',
        'is_super_admin' => false,
    ]);

    (new SuperAdminSeeder)->run();

    $owner->refresh();

    expect($owner->is_super_admin)->toBeTrue()
        ->and($owner->tenant_id)->toBeNull()
        ->and(Hash::check('admin@1234', $owner->password))->toBeTrue();

    // Converted, not duplicated.
    expect(User::withoutGlobalScopes()->where('email', 'admin@gmail.com')->count())->toBe(1);
});

it('produces an account the console accepts and the operator app does not resolve a tenant for', function () {
    app()->detectEnvironment(fn () => 'local');

    (new SuperAdminSeeder)->run();

    $admin = User::withoutGlobalScopes()->where('email', 'admin@gmail.com')->firstOrFail();

    $this->actingAs($admin)->get('/admin')->assertOk();

    // No tenant context to leak into a scoped query.
    expect($admin->tenant_id)->toBeNull();
});
