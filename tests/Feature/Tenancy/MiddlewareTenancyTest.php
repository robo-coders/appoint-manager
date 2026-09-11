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

    app('router')->setRoutes(new RouteCollection);
    SurfaceRoutes::register();

    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();
    $routes->refreshActionLookups();
    app('url')->setRoutes($routes);
}

/** @return array<string, mixed> */
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

    app(TenantContext::class)->clear();

    return compact('tenant', 'owner', 'colleague', 'customer', 'service', 'booking', 'timeOff', 'waitlistEntry');
}

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

it('refuses a foreign :route on the binding alone, with every policy allowing', function (string $route) {
    onTheRealHosts();

    Gate::before(fn () => true);

    $mine = aSalonWithOneOfEverything('Willow Street');
    $theirs = aSalonWithOneOfEverything('Rival Road');

    expect(Gate::forUser($mine['owner'])->allows('update', $theirs['colleague']))
        ->toBeTrue('the policy was not disabled, so this test proves nothing');

    [$verb, $name, $pick, $body] = boundOperatorRoutes()[$route];

    $this->actingAs($mine['owner'])
        ->$verb(route($name, $pick($theirs)), $body)
        ->assertNotFound();
})->with(['staff', 'availability']);

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

it('still hands a super admin into another tenant through the signed impersonation link', function () {
    onTheRealHosts();

    $salon = aSalonWithOneOfEverything('Willow Street');
    $admin = User::factory()->superAdmin()->create();

    app(TenantContext::class)->clear();

    $this->get(ImpersonationController::handoffUrl($salon['owner'], $admin))
        ->assertRedirect(app_url('diary'));

    $this->assertAuthenticatedAs($salon['owner']);
});

it('lists only this salon waitlist entries on a request with no hand-set context', function () {
    onTheRealHosts();

    $mine = aSalonWithOneOfEverything('Willow Street');
    $theirs = aSalonWithOneOfEverything('Rival Road');

    $response = $this->actingAs($mine['owner'])->get(route('waitlist.index'))->assertOk();
    $ids = collect($response->viewData('page')['props']['entries'])->pluck('id');

    expect($ids)->toContain($mine['waitlistEntry']->id)
        ->and($ids)->not->toContain($theirs['waitlistEntry']->id);
});

it('scopes every operator index to the salon the middleware resolved', function (string $name) {
    onTheRealHosts();

    $mine = aSalonWithOneOfEverything('Willow Street');
    aSalonWithOneOfEverything('Rival Road');

    $this->actingAs($mine['owner'])->get(route($name))->assertOk();
})->with(['customers.index', 'bookings.index', 'services.index', 'staff.index', 'waitlist.index', 'time-off.index']);

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

it('sends a signed-out visitor to login rather than through the tenant middleware', function () {
    onTheRealHosts();

    $salon = aSalonWithOneOfEverything('Willow Street');

    $this->get(route('customers.show', $salon['customer']))
        ->assertRedirect(Surface::App->path('login'));
});
