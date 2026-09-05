<?php

use App\Models\Tenant;
use App\Models\User;

/**
 * The billing read-only lock, and the one account it does not apply to.
 *
 * `EnsureSubscriptionWrite` turns every POST/PUT/PATCH/DELETE on the operator
 * app into a bounce with a toast once a tenant's trial has lapsed and nothing
 * has been paid. The public booking page keeps working; the diary goes
 * read-only.
 *
 * The agreed exception, and the reason it is written this narrowly: **a super
 * admin is not subject to a tenant's billing lock unless they are
 * impersonating.** Outside impersonation the lock is meaningless in our
 * direction — it exists to stop an unpaid salon writing to its own diary, and we
 * are not that salon. Inside impersonation it must hold, because "I want to see
 * exactly what she sees" is what impersonation is for, and a lock a support
 * session can walk through is a lock nobody can reproduce a ticket against.
 *
 * `impersonator_id` is the only thing that separates the two cases:
 * `ImpersonationController::start` logs in as the *owner*, so the authenticated
 * user's `is_super_admin` is false either way and the session key is the sole
 * evidence.
 */
function aLockedTenant(): Tenant
{
    $tenant = Tenant::factory()->create([
        'subscription_status' => 'trial',
        'trial_ends_at' => now()->subDay(),
    ]);

    expect($tenant->isReadOnly())->toBeTrue();

    return $tenant;
}

/**
 * @return array<string, mixed>
 */
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

    /*
     * A lock that traps a support session inside somebody else's unpaid account
     * is worse than no lock. `impersonation.stop` is named in the middleware's
     * exemption list, and it also sits outside the `subscribed` group — belt and
     * braces on the one route that has to keep working.
     *
     * 409 with `X-Inertia-Location` is the answer `Inertia::location` gives to
     * an Inertia visit, which is what the control in the app actually sends; see
     * `ImpersonationController::stop` for why it is not a 302. The header is on
     * the request here for that reason — without it the same call is a plain
     * redirect and the assertion would be testing the wrong path.
     */
    $this->actingAs($owner)
        ->withSession(['impersonator_id' => $admin->id])
        ->withHeader('X-Inertia', 'true')
        ->post(route('impersonation.stop'))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

it('does not gate a read', function () {
    $tenant = aLockedTenant();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => false]);

    $this->actingAs($owner)->get(route('settings.edit'))->assertOk();
});
