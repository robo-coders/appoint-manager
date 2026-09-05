<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Putting a salon into the beta, from the console. See BETA_SANDBOX.md.
 *
 * The flag is what pins a salon to Stripe test mode and hands it three buttons
 * that delete its own data, so who may set it is not a detail. It lives on the
 * admin surface behind `super-admin`, it is audited like every other write
 * there, and an owner cannot reach it — the console is a different host with a
 * different session, and this asserts that rather than trusting the routing.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/London'));
});

it('lets a super admin put a salon into the beta, and take it out again', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)
        ->post(route('super-admin.beta', $tenant), ['is_beta' => true])
        ->assertRedirect();

    expect($tenant->fresh()->is_beta)->toBeTrue();

    $this->actingAs($admin)
        ->post(route('super-admin.beta', $tenant), ['is_beta' => false])
        ->assertRedirect();

    expect($tenant->fresh()->is_beta)->toBeFalse();
});

it('writes the change to the audit log like every other console write', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->post(route('super-admin.beta', $tenant), ['is_beta' => true]);

    $entry = AuditLog::query()
        ->where('target_tenant_id', $tenant->id)
        ->where('action', 'tenant.beta')
        ->sole();

    expect($entry->actor_id)->toBe($admin->id);
    expect($entry->meta['is_beta'])->toBeTrue();
});

it('refuses a salon owner trying to flag themselves into the beta', function () {
    $salon = aSalon();

    actingAsTenant($salon['staff'])
        ->post(route('super-admin.beta', $salon['tenant']), ['is_beta' => true])
        ->assertForbidden();

    expect($salon['tenant']->fresh()->is_beta)->toBeFalse();
});

it('shows the flag on the console so a checkbox can reflect it', function () {
    $tenant = Tenant::factory()->create(['is_beta' => true]);
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)
        ->get(route('super-admin.index'))
        ->assertInertia(fn ($page) => $page->where('tenants.0.is_beta', true));
});
