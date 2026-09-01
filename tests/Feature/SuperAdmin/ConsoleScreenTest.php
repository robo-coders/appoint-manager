<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Surface;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The console's screens, and the one dangerous action on them.
 *
 * These assert the *facts the screens are built on* rather than their markup:
 * that a salon in trouble is marked as being in trouble, that the state is a
 * phrase rather than three fields joined by spaces, and that the escape hatch
 * out of an impersonated session actually works.
 */
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

/*
|--------------------------------------------------------------------------
| Who is in trouble
|--------------------------------------------------------------------------
|
| The screen opens on this rather than on name order. A hundred salons in
| alphabetical order is a directory; the question at 2am is which one is broken.
|
*/

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

/*
 * Comped wins over everything. A salon we are not charging cannot have a
 * payment problem, and marking one as needing attention would put a permanent
 * false positive at the top of the list.
 */
it('does not report a comped salon as broken, whatever its subscription says', function () {
    $admin = aSuperAdmin();
    aConsoleTenant(['is_comped' => true, 'subscription_status' => 'past_due']);

    consoleIndex($admin)->assertInertia(fn ($page) => $page
        ->where('tenants.0.state', 'Comped')
        ->where('tenants.0.needs_attention', false));
});

/*
 * The screen names the person before it borrows their session, so it has to
 * know who they are.
 */
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
        // The exact instant is still there for anything that has to sort on it.
        ->whereNot('tenants.0.last_activity_at', null));
});

it('says Never rather than a dash for a salon that has never opened the app', function () {
    $admin = aSuperAdmin();
    aConsoleTenant(['last_activity_at' => null]);

    consoleIndex($admin)->assertInertia(fn ($page) => $page->where('tenants.0.last_seen_label', 'Never'));
});

/*
|--------------------------------------------------------------------------
| Failures
|--------------------------------------------------------------------------
|
| This screen used to `JSON.stringify` the whole `failed_jobs` row into a
| `<pre>`: several hundred lines of serialised closure with the one useful
| sentence somewhere inside it. The class and the message are columns now.
|
*/

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

/*
|--------------------------------------------------------------------------
| Stopping an impersonated session
|--------------------------------------------------------------------------
|
| Logged in DECISIONS.md as broken and fixed in this phase. The control that
| calls this lives inside the tenant's app, so the request is an Inertia visit:
| a plain redirect is followed by the Inertia client as XHR, which then receives
| an HTML document for a different origin it has no page component for. In
| subdomain mode the browser refuses it outright; without subdomains it paints
| the console inside the salon's shell. Either way the one way out of somebody
| else's session did not work.
|
*/

it('ends an impersonated session with an Inertia location, not a followed redirect', function () {
    $admin = aSuperAdmin();
    $tenant = aConsoleTenant();
    $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $response = $this->actingAs($owner)
        ->withSession(['impersonator_id' => $admin->id])
        // The header the Inertia client sends on every visit. Without it this
        // test would pass against the bug.
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

/*
|--------------------------------------------------------------------------
| The console's density
|--------------------------------------------------------------------------
|
| `tokens.css` has carried `[data-density='console']` since the density pass and
| nothing had ever set it, so the one surface it was written for rendered at
| operator density. It is set on the surface's own root now.
|
| Asserted in both routing modes, because the obvious implementation —
| `Surface::fromHost` — is correct in only one of them: with subdomain routing
| off, every surface shares a host and `fromHost` answers `App` for all of them.
| That would have made this dead code locally and in CI and live only in
| production, which is the worst place to find out.
|
*/

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

/*
|--------------------------------------------------------------------------
| Three things DECISIONS.md queued for this phase
|--------------------------------------------------------------------------
*/

/*
 * "A super admin bypasses the tenant billing read-only lock when *not*
 * impersonating, but stays subject to it while impersonating." The stated
 * reason for the second half: "I want to see exactly what she sees" — a lock a
 * super admin can walk through while wearing an owner's session is a lock
 * nobody can reproduce a support ticket against.
 */
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

    // Not the read-only toast. Validation may still refuse the payload; the
    // lock must not be what refuses it.
    $response->assertSessionMissing('toast');
});

it('keeps a super admin inside the lock while they are impersonating', function () {
    $admin = aSuperAdmin();
    $tenant = aConsoleTenant(['onboarding_completed_at' => now()]);
    $tenant->forceFill(['subscription_status' => 'past_due', 'trial_ends_at' => now()->subDays(60)])->save();

    $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', 'owner')->sole();

    /*
     * While impersonating, the authenticated user *is* the owner — so
     * `is_super_admin` is false either way and the session key is the only
     * thing that distinguishes the two cases.
     */
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

/*
 * "The console has no logout control." It had one — aimed at `route('logout')`,
 * which is the app surface's route, on a surface with its own session and its
 * own `admin.logout`.
 */
it('points the console’s sign-out at the console’s own route', function () {
    $this->actingAs(aSuperAdmin())
        ->get(route('super-admin.index'))
        ->assertOk()
        // The tenant-less shell is the console shell.
        ->assertInertia(fn ($page) => $page->where('tenant', null));

    expect(file_get_contents(resource_path('js/Layouts/AppLayout.vue')))
        ->toContain("route('admin.logout')");
});

/*
 * "A guest hitting an admin route under path-fallback routing is sent to the
 * app login, not the console login." `Surface::fromHost` returns `App`
 * unconditionally when subdomain routing is off, so every surface shared one
 * answer and the console's door was the operator's.
 */
it('sends a guest on an admin route to the console login, not the app login', function () {
    config(['app.subdomain_routing' => false]);

    $this->get(route('super-admin.index'))->assertRedirect(Surface::Admin->to('login'));
});

it('still sends a guest on an app route to the app login', function () {
    config(['app.subdomain_routing' => false]);

    $this->get(route('dashboard'))->assertRedirect(Surface::App->to('login'));
});
