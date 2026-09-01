<?php

use App\Enums\Weekday;
use App\Http\Controllers\ImpersonationController;
use App\Http\Middleware\ResolveTenant;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Surface;
use App\Support\SurfaceRoutes;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * The tenant context, built by the middleware and by nothing else.
 *
 * `RouteBindingOrderTest` proved the priority-list fix on three routes. This is
 * the layer under it: **every** model-bound operator route, on the real
 * hostnames, reached the way a browser reaches them — a session cookie and
 * nothing more. No `actingAsTenant()`, no hand-set `TenantContext`, no
 * `withoutMiddleware()`.
 *
 * Two assertions per route, and the second is the one that matters. A 200
 * proves `ResolveTenant` ran before `SubstituteBindings`; a 404 on another
 * salon's row proves the ordering bought reachability without costing
 * isolation. A route that answered 200 to both would be the far worse bug — the
 * 404 everybody saw was `TenantScope` failing closed, which is the safe end of
 * this failure, and a passing 200 with the scope disarmed looks identical from
 * the outside until a customer list has somebody else's clients in it.
 *
 * Everything here runs with subdomain routing on, because that is production.
 * The path-fallback mode shares one host and therefore one middleware stack, so
 * it cannot fail differently; `PathFallbackTest` covers the routing half of it.
 */

/**
 * Bind the route table to the four production hostnames.
 *
 * Deliberately not the `withSubdomains()` in `SurfaceRoutingTest`: a helper
 * declared in a test file only exists once that file has been loaded, and
 * borrowing one across files is what makes `pest --parallel` fatal on this
 * suite today (see DECISIONS.md).
 */
function onTheRealHosts(): void
{
    config([
        'app.domain' => 'appoint-manager.test',
        'app.subdomain_routing' => true,
        'app.surfaces.marketing' => 'http://appoint-manager.test',
        'app.surfaces.app' => 'http://app.appoint-manager.test',
        'app.surfaces.book' => 'http://book.appoint-manager.test',
        'app.surfaces.admin' => 'http://admin.appoint-manager.test',
    ]);

    // Rebuilt by hand because this happens after boot rather than during it:
    // the router refreshes its lookups on `booted`, and the URL generator holds
    // its own reference to the collection.
    app('router')->setRoutes(new RouteCollection);
    SurfaceRoutes::register();

    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();
    $routes->refreshActionLookups();
    app('url')->setRoutes($routes);
}

/**
 * One salon with one row of every tenant-owned kind that a route can bind.
 *
 * The fixtures need a context to be *written* — `BelongsToTenant` refuses to
 * create a tenant-owned model without one. The request must be given none, so
 * the last thing this does is clear it. That single line is the difference
 * between testing the middleware and testing the fixture.
 *
 * @return array<string, mixed>
 */
function aSalonWithOneOfEverything(string $name): array
{
    $tenant = Tenant::factory()->create(['name' => $name, 'timezone' => 'Europe/London']);
    $owner = User::factory()->for($tenant)->owner()->create();

    app(TenantContext::class)->set($tenant);

    $colleague = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_bookable' => true,
        'is_active' => true,
    ]);

    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $service = Service::factory()->create(['tenant_id' => $tenant->id, 'duration_minutes' => 60]);
    $service->staff()->attach($owner->id);

    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    $booking = Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'staff_id' => $owner->id,
        'starts_at' => CarbonImmutable::parse('2026-03-10 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC'),
    ]);

    $timeOff = TimeOff::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'starts_at' => CarbonImmutable::parse('2026-04-01 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-01 17:00:00', 'UTC'),
    ]);

    $waitlistEntry = WaitlistEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'is_active' => true,
    ]);

    // The line that makes all of this a test of the middleware.
    app(TenantContext::class)->clear();

    return compact('tenant', 'owner', 'colleague', 'customer', 'service', 'booking', 'timeOff', 'waitlistEntry');
}

/*
|--------------------------------------------------------------------------
| One model-bound route per resource, over HTTP, on the app host
|--------------------------------------------------------------------------
|
| `$route` is given the fixture bag so each case can pick the row it binds and
| build a body for the verbs that need one. The pair is run twice: once against
| the salon that owns the row, once against a rival's.
|
*/

/** @return array<string, array{0: string, 1: string, 2: callable, 3: array<string, mixed>}> */
function boundOperatorRoutes(): array
{
    return [
        'customer' => ['get', 'customers.show', fn ($s) => $s['customer'], []],
        'booking' => ['get', 'bookings.show', fn ($s) => $s['booking'], []],
        'service' => ['get', 'services.show', fn ($s) => $s['service'], []],
        'staff' => ['patch', 'staff.update', fn ($s) => $s['colleague'], ['name' => 'Renamed Colleague']],
        'availability' => ['put', 'availability.sync', fn ($s) => $s['colleague'], ['ranges' => []]],
        'time off' => ['delete', 'time-off.destroy', fn ($s) => $s['timeOff'], []],
        'customer export' => ['get', 'customers.export', fn ($s) => $s['customer'], []],
    ];
}

it('resolves a bound :resource for the salon that owns it', function (string $resource) {
    onTheRealHosts();

    [$verb, $name, $pick, $body] = boundOperatorRoutes()[$resource];
    $mine = aSalonWithOneOfEverything('Willow Street');

    $response = $this->actingAs($mine['owner'])->$verb(route($name, $pick($mine)), $body);

    // A redirect is a successful write; what is being asserted is that the row
    // was found at all, which a 404 would deny.
    expect($response->getStatusCode())->toBeLessThan(400,
        "{$resource}: the middleware did not build a context before route model binding");
})->with(array_keys(boundOperatorRoutes()));

it('refuses a bound :resource belonging to another salon', function (string $resource) {
    onTheRealHosts();

    [$verb, $name, $pick, $body] = boundOperatorRoutes()[$resource];
    $mine = aSalonWithOneOfEverything('Willow Street');
    $theirs = aSalonWithOneOfEverything('Rival Road');

    $this->actingAs($mine['owner'])
        ->$verb(route($name, $pick($theirs)), $body)
        ->assertNotFound();
})->with(array_keys(boundOperatorRoutes()));

/*
 * The write has to be checked as well as the status. `staff.update` redirects
 * on success and `TenantScope` turns a cross-tenant bind into a 404, so both
 * halves above would still pass if the binding had somehow resolved the rival's
 * row and updated it before redirecting. This is the row itself.
 */
it('leaves another salon staff record untouched when the update is refused', function () {
    onTheRealHosts();

    $mine = aSalonWithOneOfEverything('Willow Street');
    $theirs = aSalonWithOneOfEverything('Rival Road');
    $before = $theirs['colleague']->name;

    $this->actingAs($mine['owner'])
        ->patch(route('staff.update', $theirs['colleague']), ['name' => 'Renamed By A Stranger'])
        ->assertNotFound();

    expect(User::withoutGlobalScopes()->find($theirs['colleague']->id)->name)->toBe($before);
});

/*
|--------------------------------------------------------------------------
| The staff routes refuse twice, like everything else
|--------------------------------------------------------------------------
|
| This is the finding this file was written to catch, now closed.
|
| `staff.update` and `availability.sync` used to answer **403**, not 404, when
| handed another salon's row. `User` overrode `tenantScopeFailClosed()` to
| false — login has to find a person before anyone knows their tenant — so
| `User` was the one model whose binding could not fail closed. The rival's row
| bound successfully and `StaffPolicy` was the only thing between it and an
| `update()`. Every other resource in `boundOperatorRoutes()` refuses twice; the
| staff routes refused once.
|
| So the test disarms the policy. `Gate::before(fn () => true)` allows every
| authorization check in the process — the `authorize()` inside
| `AvailabilityController::sync`, and the `can('update', …)` inside
| `UpdateStaffRequest::authorize()`, which is where `staff.update` was actually
| being caught. What is left is the binding on its own.
|
| A 404 here means the row was never found. A 403 would mean the policy is
| still doing the work and the exemption has only been moved, not narrowed; a
| 200 would mean nothing is doing the work at all.
|
*/

it('refuses a foreign :route on the binding alone, with every policy allowing', function (string $route) {
    onTheRealHosts();

    Gate::before(fn () => true);

    $mine = aSalonWithOneOfEverything('Willow Street');
    $theirs = aSalonWithOneOfEverything('Rival Road');

    // The disarm is real: with the policy in force this is false.
    expect(Gate::forUser($mine['owner'])->allows('update', $theirs['colleague']))
        ->toBeTrue('the policy was not disabled, so this test proves nothing');

    [$verb, $name, $pick, $body] = boundOperatorRoutes()[$route];

    $this->actingAs($mine['owner'])
        ->$verb(route($name, $pick($theirs)), $body)
        ->assertNotFound();
})->with(['staff', 'availability']);

/*
 * And now with no context either, which is the case that actually mattered.
 *
 * With `ResolveTenant` in place there is always a context by the time bindings
 * run, so `TenantScope` narrows the query to this salon and the rival's row is
 * out of reach whether or not `User` fails closed. The exemption only ever bit
 * where there was no context — which, before the priority fix, was every
 * request, and is still every console command, queue job and support script.
 *
 * So this drops `ResolveTenant` to put the process back in that state, and
 * drops the policy as well. Nothing is left but the model. Before the
 * narrowing this was a 302 and a renamed rival; `User` was the one model that
 * would hand a foreign row to a binder with nothing set.
 */
it('refuses a foreign :route with no context and no policy at all', function (string $route) {
    onTheRealHosts();

    Gate::before(fn () => true);

    $mine = aSalonWithOneOfEverything('Willow Street');
    $theirs = aSalonWithOneOfEverything('Rival Road');

    [$verb, $name, $pick, $body] = boundOperatorRoutes()[$route];

    $this->actingAs($mine['owner'])
        ->withoutMiddleware(ResolveTenant::class)
        ->$verb(route($name, $pick($theirs)), $body)
        ->assertNotFound();

    expect(app(TenantContext::class)->id())->toBeNull(
        'ResolveTenant still ran, so the scope had a tenant to narrow to and the binding was never on its own');
})->with(['staff', 'availability']);

/*
 * The row itself, because a 404 that arrived after the write would look
 * identical from the outside.
 */
it('leaves a foreign staff record untouched with no context and no policy', function () {
    onTheRealHosts();

    Gate::before(fn () => true);

    $mine = aSalonWithOneOfEverything('Willow Street');
    $theirs = aSalonWithOneOfEverything('Rival Road');
    $before = $theirs['colleague']->name;

    $this->actingAs($mine['owner'])
        ->withoutMiddleware(ResolveTenant::class)
        ->patch(route('staff.update', $theirs['colleague']), ['name' => 'Renamed With Nothing In The Way'])
        ->assertNotFound();

    expect(User::withoutGlobalScopes()->find($theirs['colleague']->id)->name)->toBe($before);
});

/*
 * The exemption did not disappear, it moved. Login still has to find a person
 * with no tenant context — that is the whole reason `User` was fail-open — so
 * the narrowing is only honest if the auth surface still works from a cold
 * process with nothing set.
 */
it('still authenticates a user with no tenant context anywhere', function () {
    onTheRealHosts();

    $salon = aSalonWithOneOfEverything('Willow Street');
    $salon['owner']->forceFill(['password' => Hash::make('correct-horse')])->save();

    app(TenantContext::class)->clear();

    $this->post(app_url('login'), [
        'email' => $salon['owner']->email,
        'password' => 'correct-horse',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($salon['owner']);
});

it('still finds a user by email for a password reset with no tenant context', function () {
    onTheRealHosts();

    $salon = aSalonWithOneOfEverything('Willow Street');

    app(TenantContext::class)->clear();

    expect(Password::broker()->getUser(['email' => $salon['owner']->email]))
        ->not->toBeNull('the password broker could not find a user, so nobody can reset a password');
});

/*
 * The impersonation handoff is the one route that binds a `User` across
 * tenants, and it has to: a super admin has no tenant, so there is no scope the
 * target could be found inside. It is not the old exemption coming back — the
 * signature, the single-use nonce and the super-admin recheck all still run.
 * Pinned here so that binding stays visible next to the ones that must not.
 */
it('still hands a super admin into another tenant through the signed impersonation link', function () {
    onTheRealHosts();

    $salon = aSalonWithOneOfEverything('Willow Street');
    $admin = User::factory()->superAdmin()->create();

    app(TenantContext::class)->clear();

    $this->get(ImpersonationController::handoffUrl($salon['owner'], $admin))
        ->assertRedirect(app_url('diary'));

    $this->assertAuthenticatedAs($salon['owner']);
});

/*
 * Waitlist entries have no model-bound route — `/waitlist` is an index and a
 * store, and nothing takes a `{waitlist_entry}`. So the equivalent assertion is
 * the one the resource can actually make: the list a request builds with a
 * middleware-supplied context contains this salon's entries and no others.
 * Worth stating explicitly rather than skipping, because "no bound route" is
 * the reason it is not in the table above, not an oversight.
 */
it('lists only this salon waitlist entries on a request with no hand-set context', function () {
    onTheRealHosts();

    $mine = aSalonWithOneOfEverything('Willow Street');
    $theirs = aSalonWithOneOfEverything('Rival Road');

    $response = $this->actingAs($mine['owner'])->get(route('waitlist.index'))->assertOk();
    $ids = collect($response->viewData('page')['props']['entries'])->pluck('id');

    expect($ids)->toContain($mine['waitlistEntry']->id)
        ->and($ids)->not->toContain($theirs['waitlistEntry']->id);
});

/*
 * And the index screens generally, because they are what made the binding bug
 * survivable: every one of them queries inside the controller, so they all
 * worked while everything you could click on them 404'd. If the context ever
 * stops being built, these go empty rather than going wrong — a leak here would
 * mean the scope had been disarmed instead.
 */
it('scopes every operator index to the salon the middleware resolved', function (string $name) {
    onTheRealHosts();

    $mine = aSalonWithOneOfEverything('Willow Street');
    aSalonWithOneOfEverything('Rival Road');

    $this->actingAs($mine['owner'])->get(route($name))->assertOk();
})->with(['customers.index', 'bookings.index', 'services.index', 'staff.index', 'waitlist.index', 'time-off.index']);

/*
|--------------------------------------------------------------------------
| The booking host — no user at all, tenant resolved from the slug
|--------------------------------------------------------------------------
|
| `ResolvePublicTenant` is the other half of the story and has the opposite
| problem: there is no authenticated user to read a tenant from, so the slug in
| the URL is the only input. It guards routes that take strings and load their
| own models, which is why the binding order never affected it — but "not
| affected by that bug" is not the same as "covered".
|
*/

it('resolves a tenant from the slug for a visitor with no session', function () {
    onTheRealHosts();

    $salon = aSalonWithOneOfEverything('Willow Street');

    $this->get(book_url($salon['tenant']))
        ->assertOk()
        ->assertSee($salon['tenant']->name, escape: false);
});

it('serves one salon booking page from the slug and never another', function () {
    onTheRealHosts();

    $mine = aSalonWithOneOfEverything('Willow Street');
    $theirs = aSalonWithOneOfEverything('Rival Road');

    $this->get(book_url($mine['tenant']))
        ->assertOk()
        ->assertSee('Willow Street', escape: false)
        ->assertDontSee('Rival Road', escape: false);
});

/*
 * The availability endpoint is the one that returns rows rather than a page, so
 * it is the one where a missing context would be visible as another salon's
 * diary. Asked for a service belonging to somebody else, it must not answer.
 */
it('will not price another salon service from the booking host', function () {
    onTheRealHosts();

    $mine = aSalonWithOneOfEverything('Willow Street');
    $theirs = aSalonWithOneOfEverything('Rival Road');

    $response = $this->getJson(book_url($mine['tenant'], 'availability').'?'.http_build_query([
        'service_id' => $theirs['service']->id,
        'from' => '2026-03-09',
        'to' => '2026-03-16',
    ]));

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400,
        'the public availability endpoint served a service belonging to another salon');
});

/*
 * A salon whose page is dark, or whose onboarding is unfinished, is a 404 —
 * which is `ResolvePublicTenant` and not the route table. A new registration
 * starts in exactly this state, so this is the first thing that would break if
 * the middleware stopped running.
 */
it('404s the booking host for a salon that has not gone live', function () {
    onTheRealHosts();

    $salon = aSalonWithOneOfEverything('Willow Street');
    $salon['tenant']->forceFill(['booking_page_live' => false])->save();

    $this->get(book_url($salon['tenant']))->assertNotFound();
});

it('404s the booking host for an unfinished onboarding', function () {
    onTheRealHosts();

    $salon = aSalonWithOneOfEverything('Willow Street');
    $salon['tenant']->forceFill(['onboarding_completed_at' => null])->save();

    $this->get(book_url($salon['tenant']))->assertNotFound();
});

/*
 * And an operator route reached with no user at all is a redirect to login, not
 * a 403 from `ResolveTenant`. Worth pinning: the middleware aborts 403 when
 * `$request->user()` is null, and it only reads as a redirect because `auth`
 * runs first. That ordering is now load-bearing in two directions.
 */
it('sends a signed-out visitor to login rather than through the tenant middleware', function () {
    onTheRealHosts();

    $salon = aSalonWithOneOfEverything('Willow Street');

    // Relative. `redirectGuestsTo` returns `Surface::App->path('login')`
    // so the browser stays on this host. `app_url('login')` is built from
    // APP_URL and would send a 127.0.0.1 visitor to localhost.
    $this->get(route('customers.show', $salon['customer']))
        ->assertRedirect(Surface::App->path('login'));
});
