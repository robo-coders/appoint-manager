<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

function aFilteredList(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

    $salon = aSalon();
    $owner = $salon['staff'];
    $owner->forceFill(['role' => 'owner'])->save();
    app(TenantContext::class)->set($salon['tenant']);

    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'name' => 'Ada Early']);

    $make = function (string $day, BookingStatus $status) use ($salon, $customer) {
        Booking::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'staff_id' => $salon['staff']->id,
            'service_id' => $salon['service']->id,
            'customer_id' => $customer->id,
            'starts_at' => CarbonImmutable::parse($day.' 10:00:00', 'Europe/London')->utc(),
            'ends_at' => CarbonImmutable::parse($day.' 11:00:00', 'Europe/London')->utc(),
            'status' => $status,
            'price_at_booking' => 3500,
        ]);
    };

    $make('2026-03-10', BookingStatus::Confirmed);
    $make('2026-03-11', BookingStatus::Confirmed);
    $make('2026-03-12', BookingStatus::Pending);
    $make('2026-03-13', BookingStatus::Cancelled);
    $make('2026-03-14', BookingStatus::Completed);
    $make('2026-04-02', BookingStatus::Confirmed);

    app(TenantContext::class)->clear();

    return [$owner, $salon];
}

it('counts every status in the window, whichever tab is selected', function () {
    [$owner] = aFilteredList();

    $window = ['from' => '2026-03-01', 'to' => '2026-03-31'];

    actingAsTenant($owner)
        ->get(route('bookings.index', $window))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Bookings/Index')
            ->where('counts.total', 5)
            ->where('counts.confirmed', 2)
            ->where('counts.pending', 1)
            ->where('counts.cancelled', 1)
            ->where('counts.completed', 1)
            ->where('counts.no_show', 0));

    actingAsTenant($owner)
        ->get(route('bookings.index', [...$window, 'status' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('counts.total', 5)
            ->where('counts.confirmed', 2));
});

it('counts inside the date window and not outside it', function () {
    [$owner] = aFilteredList();

    actingAsTenant($owner)
        ->get(route('bookings.index', ['from' => '2026-03-01', 'to' => '2026-03-11']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.total', 2)
            ->where('counts.confirmed', 2)
            ->where('counts.pending', 0));
});

it('exports the whole filtered list rather than the page she is looking at', function () {
    [$owner] = aFilteredList();

    $response = actingAsTenant($owner)
        ->get(route('bookings.export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'status' => 'confirmed']));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toContain('When', 'Customer', 'Amount');
    expect($lines)->toHaveCount(3);
    expect($csv)->toContain('Ada Early')->not->toContain('cancelled');
});

it('refuses the export to somebody who cannot see the list', function () {
    [, $salon] = aFilteredList();

    $this->get(route('bookings.export'))->assertRedirect(route('login'));

    $other = aSalon();

    actingAsTenant($other['staff'])
        ->get(route('bookings.export', ['from' => '2026-03-01', 'to' => '2026-03-31']))
        ->assertOk();

    expect($salon['tenant']->id)->not->toBe($other['tenant']->id);
});

it('counts only this salon, never the rows next door', function () {
    [$owner] = aFilteredList();

    $other = aSalon();
    app(TenantContext::class)->set($other['tenant']);

    $theirCustomer = Customer::factory()->create(['tenant_id' => $other['tenant']->id, 'name' => 'Bea Next-Door']);

    foreach (['2026-03-10', '2026-03-11', '2026-03-12'] as $day) {
        Booking::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'staff_id' => $other['staff']->id,
            'service_id' => $other['service']->id,
            'customer_id' => $theirCustomer->id,
            'starts_at' => CarbonImmutable::parse($day.' 10:00:00', 'Europe/London')->utc(),
            'ends_at' => CarbonImmutable::parse($day.' 11:00:00', 'Europe/London')->utc(),
            'status' => BookingStatus::Confirmed,
            'price_at_booking' => 3500,
        ]);
    }

    app(TenantContext::class)->clear();

    actingAsTenant($owner)
        ->get(route('bookings.index', ['from' => '2026-03-01', 'to' => '2026-03-31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.total', 5)
            ->where('counts.confirmed', 2)
            ->where('counts.pending', 1));
});
