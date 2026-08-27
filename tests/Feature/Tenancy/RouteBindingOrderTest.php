<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Route model binding has to happen *after* the tenant context exists.
 *
 * `SubstituteBindings` is in Laravel's own middleware priority list and
 * `ResolveTenant` — a route-level alias — was not, so the sort ran bindings
 * first. Every tenant-owned model is behind `TenantScope`, which fails **closed**
 * with no context: it appends `0 = 1` rather than reading across tenants. So
 * `/customers/{customer}` resolved against a query that could not match, and
 * Laravel turned the empty result into a 404.
 *
 * The effect was that every operator screen behind a model-bound route returned
 * "not found" for a row you had just clicked on in a list. `bootstrap/app.php`
 * now puts `ResolveTenant` before `SubstituteBindings` in the priority list.
 *
 * **Why no existing test caught it, which is the interesting part.** Every
 * Feature test reaches these routes through `actingAsTenant()`, and that helper
 * sets the tenant context by hand before the request. `TenantContext` is a
 * singleton for the life of the process, so the context was already there when
 * bindings ran and the middleware order never mattered. The suite was testing a
 * process that had been set up the way the middleware was supposed to set it up.
 *
 * So these use plain `actingAs()` and clear the context first, which is what a
 * real HTTP request looks like: nothing but a session cookie, and the middleware
 * doing all the work.
 */
function anOperatorAtTheirDesk(): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);
    $owner = User::factory()->for($tenant)->owner()->create();

    app(TenantContext::class)->set($tenant);

    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Naomi Ellery']);
    $service = Service::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full groom']);
    $booking = Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'staff_id' => $owner->id,
        'starts_at' => CarbonImmutable::parse('2026-03-10 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-03-10 10:30:00', 'UTC'),
    ]);

    /*
     * The line that makes this a test of the middleware rather than of the
     * fixture. Everything above needed a context to be written; the request
     * below must be given none.
     */
    app(TenantContext::class)->clear();

    return compact('tenant', 'owner', 'customer', 'service', 'booking');
}

it('resolves a bound customer on a request that arrives with no tenant context', function () {
    $desk = anOperatorAtTheirDesk();

    $this->actingAs($desk['owner'])
        ->get(route('customers.show', $desk['customer']))
        ->assertOk();
});

it('resolves a bound booking on a request that arrives with no tenant context', function () {
    $desk = anOperatorAtTheirDesk();

    $this->actingAs($desk['owner'])
        ->get(route('bookings.show', $desk['booking']))
        ->assertOk();
});

it('resolves a bound service on a request that arrives with no tenant context', function () {
    $desk = anOperatorAtTheirDesk();

    $this->actingAs($desk['owner'])
        ->get(route('services.show', $desk['service']))
        ->assertOk();
});

/*
 * And the ordering must not have widened anything: a row belonging to somebody
 * else is still a 404, which is `TenantScope` doing its job now that it has a
 * context to do it with. Reordering the middleware would be a poor trade if it
 * bought reachability at the cost of isolation.
 */
it('still refuses a bound model belonging to another salon', function () {
    $mine = anOperatorAtTheirDesk();
    $theirs = anOperatorAtTheirDesk();

    $this->actingAs($mine['owner'])
        ->get(route('customers.show', $theirs['customer']))
        ->assertNotFound();

    $this->actingAs($mine['owner'])
        ->get(route('bookings.show', $theirs['booking']))
        ->assertNotFound();
});
