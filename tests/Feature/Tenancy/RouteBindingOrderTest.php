<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

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
