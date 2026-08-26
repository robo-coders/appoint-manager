<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Surface;
use App\Support\SurfaceRoutes;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;

/**
 * These run with subdomain routing switched on, which is how production is
 * configured. The path-fallback mode is covered separately below.
 */
function withSubdomains(): void
{
    config([
        'app.domain' => 'appoint-manager.test',
        'app.subdomain_routing' => true,
        'app.surfaces.marketing' => 'http://appoint-manager.test',
        'app.surfaces.app' => 'http://app.appoint-manager.test',
        'app.surfaces.book' => 'http://book.appoint-manager.test',
        'app.surfaces.admin' => 'http://admin.appoint-manager.test',
    ]);

    // Rebuild the route table against the new hosts.
    //
    // Two things normally done by the framework have to be done by hand here,
    // because this happens after boot rather than during it: the name and
    // action lookups are refreshed on `booted`, and the URL generator holds its
    // own reference to the route collection.
    app('router')->setRoutes(new RouteCollection);
    SurfaceRoutes::register();

    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();
    $routes->refreshActionLookups();
    app('url')->setRoutes($routes);
}

it('serves each surface from its own host', function () {
    withSubdomains();

    $this->get('http://appoint-manager.test/')->assertOk();
    $this->get('http://appoint-manager.test/pricing')->assertOk();
});

it('does not serve marketing from the app host', function () {
    withSubdomains();

    $this->get('http://app.appoint-manager.test/pricing')->assertNotFound();
});

it('does not serve the app from the marketing host', function () {
    withSubdomains();

    $this->get('http://appoint-manager.test/diary')->assertNotFound();
});

it('does not serve the console from the app host', function () {
    withSubdomains();

    // A 404, never a 403: a salon owner should not learn the console exists.
    $this->get('http://app.appoint-manager.test/')->assertNotFound();
    $this->get('http://app.appoint-manager.test/messages')->assertNotFound();
    $this->get('http://app.appoint-manager.test/failures')->assertNotFound();
});

it('does not serve the console from the marketing or booking hosts', function () {
    withSubdomains();

    $this->get('http://book.appoint-manager.test/failures')->assertNotFound();
    $this->get('http://appoint-manager.test/failures')->assertNotFound();
});

it('does not serve the booking page from the app host', function () {
    withSubdomains();
    $tenant = Tenant::factory()->create();

    $this->get("http://app.appoint-manager.test/{$tenant->slug}")->assertNotFound();
});

it('serves the booking page from the booking host with no path prefix', function () {
    withSubdomains();
    $tenant = Tenant::factory()->create();

    $this->get("http://book.appoint-manager.test/{$tenant->slug}")->assertOk();
});

it('keeps the fixed booking prefixes reachable ahead of the slug wildcard', function () {
    withSubdomains();

    // /b/{token} and /offer/{token} must resolve to their own controllers, not
    // be swallowed by /{tenant_slug}. Both 404 on an unknown token, but the
    // route that matched is what matters.
    $this->get('http://book.appoint-manager.test/b/not-a-real-token')->assertNotFound();

    $matched = app('router')->getRoutes()->match(
        Request::create('http://book.appoint-manager.test/b/some-token', 'GET'),
    );

    expect($matched->getName())->toBe('booking.manage.show');
});

it('builds a booking URL on the booking host', function () {
    withSubdomains();
    $tenant = Tenant::factory()->create(['slug' => 'willow-street']);

    expect(book_url($tenant))->toBe('http://book.appoint-manager.test/willow-street')
        ->and(Surface::bookUrlFor($tenant))->toBe('http://book.appoint-manager.test/willow-street');
});

it('builds each surface URL on its own host', function () {
    withSubdomains();

    expect(marketing_url())->toBe('http://appoint-manager.test')
        ->and(app_url('diary'))->toBe('http://app.appoint-manager.test/diary')
        ->and(admin_url())->toBe('http://admin.appoint-manager.test')
        ->and(book_url(null, 'b/abc'))->toBe('http://book.appoint-manager.test/b/abc');
});

it('will not read an app session on the console host', function () {
    withSubdomains();

    // An app session cannot be presented to the console at all: the cookie is
    // pinned to app.{domain} so a browser never sends it, and the console reads
    // a differently named cookie so it would not look for it if one arrived.
    //
    // Only the server half of that is testable here — actingAs() bypasses
    // cookies, so a test that "presents" one would be exercising the harness
    // rather than the app.
    expect(Surface::App->cookie())->not->toBe(Surface::Admin->cookie())
        ->and(Surface::Admin->cookie())->toContain('admin');

    $this->get('http://app.appoint-manager.test/login');
    $appCookie = config('session.cookie');

    $this->get('http://admin.appoint-manager.test/login');

    expect(config('session.cookie'))->not->toBe($appCookie)
        ->and(config('session.domain'))->toBe('admin.appoint-manager.test');
});

it('shows the impersonation banner on every app screen while active', function () {
    withSubdomains();

    $tenant = Tenant::factory()->create();
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);

    $handoff = $this->actingAs($admin)
        ->post("http://admin.appoint-manager.test/tenants/{$tenant->id}/impersonate")
        ->headers->get('Location');

    $this->flushSession();
    $this->get($handoff);

    // The banner is driven by a shared Inertia prop, so it is present on every
    // screen the layout renders rather than on one of them.
    foreach (['diary', 'bookings', 'customers'] as $screen) {
        $this->get("http://app.appoint-manager.test/{$screen}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('impersonating', true));
    }
});

it('puts every route on its surface group, and the group carries the limits', function (
    string $routeName,
    string $group,
    array $expected,
) {
    withSubdomains();

    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->getName() === $routeName);

    // The surface group is what the route carries; the limits live in the group
    // so a new route on that surface inherits them without anyone adding them.
    expect($route->gatherMiddleware())->toContain($group)
        ->and(app('router')->getMiddlewareGroups()[$group])->toBe($expected);
})->with([
    ['diary.index', 'surface.app', ['throttle:app']],
    ['super-admin.index', 'surface.admin', ['admin-ip', 'throttle:admin']],
    ['marketing.home', 'surface.marketing', []],
    ['public.booking.show', 'surface.book', []],
]);

it('blocks the console from an IP outside the allowlist, with a 404', function () {
    withSubdomains();
    config(['app.admin_ip_allowlist' => ['203.0.113.4']]);
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);

    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->get('http://admin.appoint-manager.test/')
        ->assertNotFound();
});

it('rejects a salon owner on the console host', function () {
    withSubdomains();
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => false]);

    $this->actingAs($owner)->get('http://admin.appoint-manager.test/')->assertForbidden();
});

it('lets a super admin into the console host', function () {
    withSubdomains();
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);

    $this->actingAs($admin)->get('http://admin.appoint-manager.test/')->assertOk();
});

it('sends a super admin to the console after logging in, not to a diary', function () {
    withSubdomains();
    $tenant = Tenant::factory()->create(['onboarding_completed_at' => null]);
    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_super_admin' => true,
        'password' => bcrypt('password'),
    ]);

    $this->post('http://admin.appoint-manager.test/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertRedirect('http://admin.appoint-manager.test');
});

it('will not let a salon owner sign in at the console, and says nothing useful', function () {
    withSubdomains();
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_super_admin' => false,
        'password' => bcrypt('password'),
    ]);

    $this->post('http://admin.appoint-manager.test/login', [
        'email' => $owner->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('scopes the session cookie to the exact host and never the parent domain', function () {
    withSubdomains();

    $this->get('http://app.appoint-manager.test/login');
    expect(config('session.domain'))->toBe('app.appoint-manager.test')
        ->and(config('session.cookie'))->toBe(Surface::App->cookie());

    $this->get('http://admin.appoint-manager.test/login');
    expect(config('session.domain'))->toBe('admin.appoint-manager.test')
        ->and(config('session.domain'))->not->toStartWith('.')
        ->and(config('session.cookie'))->toBe(Surface::Admin->cookie());
});

it('hands impersonation across to the app surface and back again', function () {
    withSubdomains();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);

    // The console cannot set a cookie for the app host, so it hands off a
    // signed link pointing at it.
    $response = $this->actingAs($admin)
        ->post("http://admin.appoint-manager.test/tenants/{$tenant->id}/impersonate");

    $handoff = $response->headers->get('Location');
    expect($handoff)->toStartWith('http://app.appoint-manager.test/impersonate/');

    // Redeeming it issues a normal app session tagged with the impersonator.
    $this->flushSession();
    $this->get($handoff)->assertRedirect('http://app.appoint-manager.test/diary');

    expect(session('impersonator_id'))->toBe($admin->id)
        ->and(auth()->id())->toBe($owner->id)
        ->and(AuditLog::query()->where('action', 'impersonate.start')->count())->toBe(1);

    // Exiting drops the app session and returns to the console.
    $this->post('http://app.appoint-manager.test/impersonation/stop')
        ->assertRedirect('http://admin.appoint-manager.test');

    expect(AuditLog::query()->where('action', 'impersonate.stop')->count())->toBe(1);
});

it('will not let an impersonation link be redeemed twice', function () {
    withSubdomains();

    $tenant = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::Owner]);
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);

    $handoff = $this->actingAs($admin)
        ->post("http://admin.appoint-manager.test/tenants/{$tenant->id}/impersonate")
        ->headers->get('Location');

    $this->flushSession();
    $this->get($handoff)->assertRedirect();

    $this->flushSession();
    $this->get($handoff)->assertForbidden();
});
