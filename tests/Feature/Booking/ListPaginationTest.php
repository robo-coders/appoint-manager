<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

it('paginates the bookings list and sorts it on the server', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

    $salon = aSalon();
    $owner = $salon['staff'];
    $owner->forceFill(['role' => 'owner'])->save();
    app(TenantContext::class)->set($salon['tenant']);

    $early = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'name' => 'Ada Early']);
    $late = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'name' => 'Zoe Late']);

    foreach (range(1, 26) as $i) {
        Booking::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'staff_id' => $salon['staff']->id,
            'service_id' => $salon['service']->id,
            'customer_id' => $i === 1 ? $late->id : $early->id,
            'starts_at' => CarbonImmutable::parse('2026-03-10 10:00:00', 'Europe/London')->addMinutes($i)->utc(),
            'ends_at' => CarbonImmutable::parse('2026-03-10 11:00:00', 'Europe/London')->addMinutes($i)->utc(),
            'status' => BookingStatus::Confirmed,
            'price_at_booking' => $i === 1 ? 9000 : 1000,
        ]);
    }

    app(TenantContext::class)->clear();

    actingAsTenant($owner)
        ->get(route('bookings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Bookings/Index')
            ->has('bookings.data', 25)
            ->where('bookings.total', 26)
            ->where('bookings.current_page', 1)
            ->where('bookings.last_page', 2)
            ->where('filters.sort', 'when')
            ->where('filters.direction', 'desc'));

    actingAsTenant($owner)
        ->get(route('bookings.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('bookings.current_page', 2)
            ->where('bookings.total', 26));

    actingAsTenant($owner)
        ->get(route('bookings.index', ['sort' => 'customer', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('bookings.data.0.customer_name', 'Zoe Late')
            ->where('filters.sort', 'customer')
            ->where('filters.direction', 'desc'));

    actingAsTenant($owner)
        ->get(route('bookings.index', ['status' => 'cancelled']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 0)
            ->where('bookings.total', 0)
            ->where('filters.status', 'cancelled'));
});

it('paginates customers and searches on the server', function () {
    $salon = aSalon();
    $owner = $salon['staff'];
    $owner->forceFill(['role' => 'owner'])->save();
    app(TenantContext::class)->set($salon['tenant']);

    Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Ada Early',
        'email' => 'ada@example.test',
        'phone' => '07700900001',
    ]);
    Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Zoe Late',
        'email' => 'zoe@example.test',
        'phone' => '07700900002',
    ]);

    foreach (range(1, 24) as $i) {
        Customer::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'name' => sprintf('Other %02d', $i),
        ]);
    }

    app(TenantContext::class)->clear();

    actingAsTenant($owner)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Index')
            ->has('customers.data', 25)
            ->where('customers.total', 26)
            ->where('customers.last_page', 2)
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc')
            ->where('customers.data.0.name', 'Ada Early'));

    actingAsTenant($owner)
        ->get(route('customers.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('customers.data', 1)
            ->where('customers.current_page', 2));

    actingAsTenant($owner)
        ->get(route('customers.index', ['search' => 'zoe']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('customers.data', 1)
            ->where('customers.total', 1)
            ->where('customers.data.0.name', 'Zoe Late')
            ->where('filters.search', 'zoe'));

    actingAsTenant($owner)
        ->get(route('customers.index', ['sort' => 'name', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('customers.data.0.name', 'Zoe Late')
            ->where('filters.direction', 'desc'));
});
