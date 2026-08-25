<?php

use App\Models\Customer;
use App\Models\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

it('no longer exposes an anonymous customer lookup at all', function () {
    $salon = aSalon();
    $customer = Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Priya Raman',
        'email' => 'priya@example.com',
        'phone' => '+447700900123',
    ]);
    Subject::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'name' => 'Rufus',
    ]);

    $this->getJson('/book/'.$salon['tenant']->slug.'/customer-match?email=priya@example.com')
        ->assertNotFound();
});

it('does not name the route anywhere', function () {
    expect(Route::has('public.booking.customer-match'))->toBeFalse();
});

it('does not leak a customer through the public booking page payload', function () {
    $salon = aSalon();
    Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Priya Raman',
        'email' => 'priya@example.com',
        'phone' => '+447700900123',
    ]);

    $body = $this->get(route('public.booking.show', $salon['tenant']->slug))->assertOk()->getContent();

    expect($body)->not->toContain('Priya Raman')
        ->and($body)->not->toContain('+447700900123');
});

it('does not overwrite an existing customer name and phone from a public booking', function () {
    $salon = aSalon();
    $customer = Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Priya Raman',
        'email' => 'priya@example.com',
        'phone' => '+447700900123',
    ]);

    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(
        route('public.booking.store', $salon['tenant']->slug),
        aBookingPayload($salon['service'], $salon['staff'], $startsAt, 'priya@example.com') + ['name' => 'Mallory']
    );

    $customer->refresh();

    expect($customer->name)->toBe('Priya Raman')
        ->and($customer->phone)->toBe('+447700900123');
});
