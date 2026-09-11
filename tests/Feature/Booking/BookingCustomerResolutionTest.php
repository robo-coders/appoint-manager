<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

function aManualBookingPayload(array $salon, string $email, array $overrides = []): array
{
    return array_merge([
        'service_id' => $salon['service']->id,
        'staff_id' => $salon['staff']->id,
        'starts_at' => '2026-03-10T11:00',
        'customer_name' => 'Sam Lee',
        'customer_email' => $email,
        'customer_phone' => '07700900111',
    ], $overrides);
}

it('reuses the existing customer when a manual booking repeats their email', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();
    $existing = Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Samantha Lee',
        'email' => 'sam@example.com',
        'phone' => '07700900999',
    ]);

    actingAsTenant($owner)
        ->post(route('bookings.store'), aManualBookingPayload($salon, 'sam@example.com'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect($booking->customer_id)->toBe($existing->id)
        ->and(Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe(1);
});

it('leaves the existing name and phone alone when a manual booking submits different details', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();
    $existing = Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Samantha Lee',
        'email' => 'sam@example.com',
        'phone' => '07700900999',
    ]);

    actingAsTenant($owner)
        ->post(route('bookings.store'), aManualBookingPayload($salon, 'sam@example.com', [
            'customer_name' => 'Sam L',
            'customer_phone' => '07700900111',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($existing->fresh())
        ->name->toBe('Samantha Lee')
        ->phone->toBe('07700900999');
});

it('creates a new customer when a manual booking uses an email held by another tenant', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();
    $other = Tenant::factory()->create();
    $foreign = Customer::factory()->create([
        'tenant_id' => $other->id,
        'email' => 'sam@example.com',
    ]);

    actingAsTenant($owner)
        ->post(route('bookings.store'), aManualBookingPayload($salon, 'sam@example.com'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();
    $customer = Customer::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->where('email', 'sam@example.com')
        ->sole();

    expect($booking->customer_id)->toBe($customer->id)
        ->and($customer->id)->not->toBe($foreign->id)
        ->and($customer->name)->toBe('Sam Lee');
});

it('creates a new customer when a manual booking uses an email nobody holds', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();

    actingAsTenant($owner)
        ->post(route('bookings.store'), aManualBookingPayload($salon, 'new@example.com'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $customer = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect($customer->email)->toBe('new@example.com')
        ->and($customer->name)->toBe('Sam Lee')
        ->and(Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole()->customer_id)
        ->toBe($customer->id);
});

it('reuses the existing customer when a public booking repeats their email', function () {
    $salon = aSalon();
    $existing = Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Samantha Lee',
        'email' => 'alex@example.com',
        'phone' => '07700900999',
    ]);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(
        route('public.booking.store', $salon['tenant']->slug),
        aBookingPayload($salon['service'], $salon['staff'], $startsAt),
    )->assertCreated();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect($booking->customer_id)->toBe($existing->id)
        ->and(Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('Samantha Lee')
        ->and($existing->fresh()->phone)->toBe('07700900999');
});

it('creates a new customer when a public booking uses an email held by another tenant', function () {
    $salon = aSalon();
    $other = Tenant::factory()->create();
    $foreign = Customer::factory()->create([
        'tenant_id' => $other->id,
        'email' => 'alex@example.com',
    ]);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(
        route('public.booking.store', $salon['tenant']->slug),
        aBookingPayload($salon['service'], $salon['staff'], $startsAt),
    )->assertCreated();

    $customer = Customer::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->where('email', 'alex@example.com')
        ->sole();

    expect($customer->id)->not->toBe($foreign->id)
        ->and($customer->name)->toBe('Alex Reed');
});

it('keeps a manual booking working when no email is given', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();

    actingAsTenant($owner)
        ->post(route('bookings.store'), aManualBookingPayload($salon, '', ['customer_email' => null]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $customer = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect($customer->email)->toBeNull()
        ->and($customer->name)->toBe('Sam Lee');
});
