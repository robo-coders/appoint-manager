<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

it('shows the diary, bookings list and customer detail for the current tenant only', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);
    $other = Tenant::factory()->create();
    $owner = User::factory()->for($tenant)->owner()->create();
    $staff = User::factory()->for($tenant)->staff()->create(['is_bookable' => true]);
    $service = Service::factory()->for($tenant)->create(['duration_minutes' => 60]);
    $service->staff()->attach($staff->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Pat Quinn']);
    $foreign = Customer::factory()->create(['tenant_id' => $other->id, 'name' => 'Hidden Client']);

    $startsAt = CarbonImmutable::parse('2026-03-10 10:00:00', 'Europe/London')->utc();
    $booking = Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $staff->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'status' => BookingStatus::Confirmed,
        'source' => BookingSource::Manual,
    ]);
    $foreignBooking = Booking::factory()->create([
        'tenant_id' => $other->id,
        'staff_id' => User::factory()->for($other)->create()->id,
        'service_id' => Service::factory()->for($other)->create()->id,
        'customer_id' => $foreign->id,
    ]);

    actingAsTenant($owner)
        ->get(route('diary.index', ['date' => '2026-03-10', 'view' => 'day']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Diary/Index')
            ->has('bookings', 1)
            ->where('bookings.0.id', $booking->id));

    $this->get(route('bookings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Bookings/Index')
            ->has('bookings', 1)
            ->where('bookings.0.customer_name', 'Pat Quinn'));

    $this->get(route('bookings.show', $booking))->assertOk();
    $this->get(route('bookings.show', $foreignBooking))->assertNotFound();

    $this->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Index')
            ->has('customers', 1)
            ->where('customers.0.name', 'Pat Quinn'));

    $this->get(route('customers.show', $customer))->assertOk();
    $this->get(route('customers.show', $foreign))->assertNotFound();
});

it('creates a manual diary booking without a deposit', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);
    $owner = User::factory()->for($tenant)->owner()->create(['is_bookable' => true]);
    $service = Service::factory()->for($tenant)->create(['duration_minutes' => 60, 'deposit_amount' => 1000]);
    $service->staff()->attach($owner->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    actingAsTenant($owner)
        ->post(route('bookings.store'), [
            'service_id' => $service->id,
            'staff_id' => $owner->id,
            'starts_at' => '2026-03-10T11:00',
            'customer_name' => 'Sam Lee',
            'customer_email' => 'sam@example.com',
            'subject_name' => 'Ash',
        ])
        ->assertRedirect();

    $booking = Booking::query()->first();

    expect($booking)->not->toBeNull()
        ->and($booking->source->value)->toBe('manual')
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->deposit_status->value)->toBe('none')
        ->and($booking->deposit_at_booking->amount)->toBe(0);
});

it('stores two walk-in bookings for clients who have no email', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);
    $owner = User::factory()->for($tenant)->owner()->create(['is_bookable' => true]);
    $service = Service::factory()->for($tenant)->create(['duration_minutes' => 60, 'deposit_amount' => 0]);
    $service->staff()->attach($owner->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    $asOwner = actingAsTenant($owner);

    $asOwner->post(route('bookings.store'), [
        'service_id' => $service->id,
        'staff_id' => $owner->id,
        'starts_at' => '2026-03-10T11:00',
        'customer_name' => 'Sam Lee',
        'customer_phone' => '07700900001',
        'subject_name' => 'Ash',
    ])->assertRedirect();

    $asOwner->post(route('bookings.store'), [
        'service_id' => $service->id,
        'staff_id' => $owner->id,
        'starts_at' => '2026-03-10T13:00',
        'customer_name' => 'Pat Cole',
        'customer_phone' => '07700900002',
        'subject_name' => 'Jet',
    ])->assertRedirect();

    $emails = Customer::query()->orderBy('name')->pluck('email')->all();

    expect($emails)->toBe([null, null])
        ->and(Customer::query()->count())->toBe(2)
        ->and(Booking::query()->count())->toBe(2);
});
