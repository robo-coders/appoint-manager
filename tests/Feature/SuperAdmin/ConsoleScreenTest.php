<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Surface;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function aSuperAdmin(): User
{
    return User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);
}

/** @param  array<string, mixed>  $attributes */
function aConsoleTenant(array $attributes = []): Tenant
{
    $tenant = Tenant::factory()->create(array_merge(['name' => 'Willow Street'], $attributes));

    app(TenantContext::class)->set($tenant);
    User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner', 'name' => 'Maya Chen']);
    app(TenantContext::class)->clear();

    return $tenant;
}

function consoleIndex(User $admin)
{
    return test()->actingAs($admin)->get(route('super-admin.index'));
}

beforeEach(fn () => test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'Europe/London')));

it('marks a salon whose payment failed as needing attention', function () {
    $admin = aSuperAdmin();
    aConsoleTenant(['subscription_status' => 'past_due']);

    consoleIndex($admin)->assertInertia(fn ($page) => $page
        ->where('tenants.0.state', 'Payment failed')
        ->where('tenants.0.needs_attention', true));
});

it('marks a salon whose trial has run out as needing attention', function () {
    $admin = aSuperAdmin();
    aConsoleTenant(['subscription_status' => 'trial', 'trial_ends_at' => now()->subDay()]);

    consoleIndex($admin)->assertInertia(fn ($page) => $page
        ->where('tenants.0.state', 'Trial over')
        ->where('tenants.0.needs_attention', true));
});

it('leaves a subscribed salon alone', function () {
    $admin = aSuperAdmin();
    aConsoleTenant(['subscription_status' => 'active']);

    consoleIndex($admin)->assertInertia(fn ($page) => $page
        ->where('tenants.0.state', 'Subscribed')
        ->where('tenants.0.needs_attention', false));
});

it('does not demote a paying salon when the trial date is moved', function () {
    $admin = aSuperAdmin();
    $tenant = aConsoleTenant([
        'subscription_status' => 'active',
        'trial_ends_at' => now()->addDays(5),
    ]);

    $this->actingAs($admin)
        ->post(route('super-admin.trial', $tenant), ['days' => 14])
        ->assertRedirect();

    expect($tenant->fresh()->subscription_status)->toBe('active')
        ->and($tenant->fresh()->trial_ends_at?->toDateString())->toBe(now()->addDays(19)->toDateString());
});

it('does not report a comped salon as broken, whatever its subscription says', function () {
    $admin = aSuperAdmin();
    aConsoleTenant(['is_comped' => true, 'subscription_status' => 'past_due']);

    consoleIndex($admin)->assertInertia(fn ($page) => $page
        ->where('tenants.0.state', 'Comped')
        ->where('tenants.0.needs_attention', false));
});

it('sends the owner’s name, because the confirm names them', function () {
    $admin = aSuperAdmin();
    aConsoleTenant();

    consoleIndex($admin)->assertInertia(fn ($page) => $page->where('tenants.0.owner_name', 'Maya Chen'));
});

it('sends last seen as a phrase rather than an ISO timestamp', function () {
    $admin = aSuperAdmin();
    aConsoleTenant(['last_activity_at' => now()->subDays(3)]);

    consoleIndex($admin)->assertInertia(fn ($page) => $page
        ->where('tenants.0.last_seen_label', '3d ago')
        ->whereNot('tenants.0.last_activity_at', null));
});

it('says Never rather than a dash for a salon that has never opened the app', function () {
    $admin = aSuperAdmin();
    aConsoleTenant(['last_activity_at' => null]);

    consoleIndex($admin)->assertInertia(fn ($page) => $page->where('tenants.0.last_seen_label', 'Never'));
});

it('pulls the exception class and first line out of a failed job', function () {
    $admin = aSuperAdmin();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendReminder', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']),
        'exception' => "RuntimeException: Twilio refused the number.\n#0 /app/vendor/…\n#1 /app/vendor/…",
        'failed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('super-admin.failures'))
        ->assertInertia(fn ($page) => $page
            ->where('failed_jobs.0.job_name', 'App\\Jobs\\SendReminder')
            ->where('failed_jobs.0.exception_class', 'RuntimeException')
            ->where('failed_jobs.0.exception_message', 'Twilio refused the number.'));
});

it('answers an empty failures screen with an empty list, not with a null', function () {
    $admin = aSuperAdmin();

    $this->actingAs($admin)
        ->get(route('super-admin.failures'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('failed_jobs', 0)->count('webhook_failures', 0));
});

it('ends an impersonated session with an Inertia location, not a followed redirect', function () {
    $admin = aSuperAdmin();
    $tenant = aConsoleTenant();
    $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $response = $this->actingAs($owner)
        ->withSession(['impersonator_id' => $admin->id])
        ->withHeader('X-Inertia', 'true')
        ->post(route('impersonation.stop'));

    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', Surface::Admin->to());

    $this->assertGuest();
});

it('still writes both ends of the impersonation to the audit log', function () {
    $admin = aSuperAdmin();
    $tenant = aConsoleTenant();
    $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $this->actingAs($owner)
        ->withSession(['impersonator_id' => $admin->id])
        ->withHeader('X-Inertia', 'true')
        ->post(route('impersonation.stop'));

    expect(AuditLog::withoutGlobalScopes()->where('action', 'impersonate.stop')->count())->toBe(1);
});

it('refuses to stop a session that was never impersonated', function () {
    $tenant = aConsoleTenant();
    $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $this->actingAs($owner)->post(route('impersonation.stop'))->assertForbidden();
});

it('renders the console at console density when surfaces are paths', function () {
    config(['app.subdomain_routing' => false]);

    $this->actingAs(aSuperAdmin())
        ->get(route('super-admin.index'))
        ->assertOk()
        ->assertSee('data-density="console"', false);
});

it('leaves the operator app at its own density', function () {
    config(['app.subdomain_routing' => false]);

    $tenant = aConsoleTenant(['onboarding_completed_at' => now()]);
    $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-density=', false);
});

it('lets a super admin write to a locked tenant when not impersonating', function () {
    $admin = aSuperAdmin();
    $tenant = aConsoleTenant(['onboarding_completed_at' => now()]);
    $tenant->forceFill(['subscription_status' => 'past_due', 'trial_ends_at' => now()->subDays(60)])->save();

    expect($tenant->fresh()->isReadOnly())->toBeTrue();

    $admin->forceFill(['tenant_id' => $tenant->id])->save();

    $response = $this->actingAs($admin->fresh())->post(route('waitlist.store'), [
        'name' => 'Naomi Ellery',
        'email' => 'naomi@example.com',
        'phone' => '07700900000',
        'service_id' => null,
    ]);

    $response->assertSessionMissing('toast');
});

it('keeps a super admin inside the lock while they are impersonating', function () {
    $admin = aSuperAdmin();
    $tenant = aConsoleTenant(['onboarding_completed_at' => now()]);
    $tenant->forceFill(['subscription_status' => 'past_due', 'trial_ends_at' => now()->subDays(60)])->save();

    $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', 'owner')->sole();

    $this->actingAs($owner)
        ->withSession(['impersonator_id' => $admin->id])
        ->post(route('waitlist.store'), [
            'name' => 'Naomi Ellery',
            'email' => 'naomi@example.com',
            'phone' => '07700900000',
            'service_id' => null,
        ])
        ->assertSessionHas('toast');
});

it('points the console’s sign-out at the console’s own route', function () {
    $this->actingAs(aSuperAdmin())
        ->get(route('super-admin.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenant', null));

    expect(file_get_contents(resource_path('js/Layouts/AppLayout.vue')))
        ->toContain("route('admin.logout')");
});

it('sends a guest on an admin route to the console login, not the app login', function () {
    config(['app.subdomain_routing' => false]);

    $this->get(route('super-admin.index'))->assertRedirect(Surface::Admin->to('login'));
});

it('still sends a guest on an app route to the app login', function () {
    config(['app.subdomain_routing' => false]);

    $this->get(route('dashboard'))->assertRedirect(Surface::App->to('login'));
});
