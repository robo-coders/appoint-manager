<?php

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;

function aConsoleAdmin(): User
{
    return User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::Owner,
        'is_super_admin' => true,
    ]);
}

it('logs a super admin out and returns them to the console door', function () {
    $this->actingAs(aConsoleAdmin())
        ->post(route('admin.logout'))
        ->assertRedirect(route('admin.login'));

    $this->assertGuest();
});

it('throws the session away rather than reusing it', function () {
    $this->actingAs(aConsoleAdmin());

    $this->withSession(['impersonator_id' => 99])
        ->post(route('admin.logout'))
        ->assertRedirect(route('admin.login'));

    $this->assertGuest();
    expect(session()->has('impersonator_id'))->toBeFalse();
});

it('will not log out a caller who is not signed in', function () {
    $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
});

it('will not log out a signed-in user who is not a super admin', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => false]);

    $this->actingAs($user)->post(route('admin.logout'))->assertForbidden();

    $this->assertAuthenticated();
});

it('remembers a dismissed notice for the rest of the session', function () {
    $this->actingAs(aConsoleAdmin())
        ->from(route('super-admin.index'))
        ->post(route('admin.notices.dismiss', 'email-verification'))
        ->assertRedirect(route('super-admin.index'));

    expect(session('dismissed_notices'))->toBe(['email-verification']);
});

it('does not grow the session with a notice nobody declared', function () {
    $this->actingAs(aConsoleAdmin())
        ->post(route('admin.notices.dismiss', 'whatever-i-like'))
        ->assertNotFound();

    expect(session('dismissed_notices'))->toBeNull();
});

it('records a notice once however many times it is dismissed', function () {
    $admin = aConsoleAdmin();

    $this->actingAs($admin)->post(route('admin.notices.dismiss', 'email-verification'));
    $this->actingAs($admin)->post(route('admin.notices.dismiss', 'email-verification'));

    expect(session('dismissed_notices'))->toBe(['email-verification']);
});

it('will not let a guest write to the session through the dismiss route', function () {
    $this->post(route('admin.notices.dismiss', 'email-verification'))
        ->assertRedirect(route('admin.login'));
});
