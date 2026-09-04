<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rebooking\OverdueSubjects;
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
            ->has('bookings.data', 1)
            ->where('bookings.data.0.customer_name', 'Pat Quinn'));

    $this->get(route('bookings.show', $booking))->assertOk();
    $this->get(route('bookings.show', $foreignBooking))->assertNotFound();

    $this->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Index')
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Pat Quinn'));

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

    $stored = actingAsTenant($owner)
        ->post(route('bookings.store'), [
            'service_id' => $service->id,
            'staff_id' => $owner->id,
            'starts_at' => '2026-03-10T11:00',
            'customer_name' => 'Sam Lee',
            'customer_email' => 'sam@example.com',
            'subject_name' => 'Ash',
            'correlation_id' => '11111111-1111-4111-8111-111111111111',
        ])
        ->assertRedirect()
        ->assertSessionHas('created_booking.correlation_id', '11111111-1111-4111-8111-111111111111')
        ->assertSessionHas('created_booking.booking.customer_name', 'Sam Lee')
        ->assertSessionHas('created_booking.booking.id');

    actingAsTenant($owner)
        ->get($stored->headers->get('Location'))
        ->assertInertia(fn ($page) => $page
            ->where('createdBooking.correlation_id', '11111111-1111-4111-8111-111111111111')
            ->where('createdBooking.booking.customer_name', 'Sam Lee'));

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

it('sends each service interval to the diary so the form can fill Come back in', function () {
    $salon = aDiarySalon();
    $salon['service']->forceFill(['suggested_interval_days' => 28])->save();

    AvailabilityRule::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'user_id' => $salon['staff']->id,
        'weekday' => Weekday::Wednesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('services.0.suggested_interval_days', 28));
});

it('writes the posted service interval onto the subject and chases on that due date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);
    $owner = User::factory()->for($tenant)->owner()->create(['is_bookable' => true]);
    $service = Service::factory()->for($tenant)->create([
        'duration_minutes' => 60,
        'deposit_amount' => 0,
        'suggested_interval_days' => 42,
        'price' => 3500,
    ]);
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
            'customer_phone' => '07700900001',
            'subject_name' => 'Ash',
            'rebook_interval_days' => 42,
        ])
        ->assertRedirect();

    $subject = Subject::query()->first();

    expect($subject?->rebook_interval_days)->toBe(42)
        ->and(Booking::query()->value('rebook_interval_days'))->toBe(42);

    $this->travelTo(CarbonImmutable::parse('2026-04-21 10:00:00', 'Europe/London'));

    $rows = app(OverdueSubjects::class)->forTenant($tenant);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['subject_name'])->toBe('Ash')
        ->and($rows[0]['due_on'])->toBe('2026-04-21');
});

it('does not write an interval when Come back in is cleared', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);
    $owner = User::factory()->for($tenant)->owner()->create(['is_bookable' => true]);
    $service = Service::factory()->for($tenant)->create([
        'duration_minutes' => 60,
        'deposit_amount' => 0,
        'suggested_interval_days' => 42,
        'price' => 3500,
    ]);
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
            'customer_phone' => '07700900001',
            'subject_name' => 'Ash',
            'rebook_interval_days' => '',
        ])
        ->assertRedirect();

    $subject = Subject::query()->first();

    expect($subject?->rebook_interval_days)->toBeNull()
        ->and(Booking::query()->value('rebook_interval_days'))->toBeNull();

    /*
     * Clearing the checkout field does not stop chasing. DECISIONS.md and
     * RebookInterval fall through to the service default; Stop is the action
     * that takes a subject off the list. The form's empty option is "The usual".
     */
    $this->travelTo(CarbonImmutable::parse('2026-04-21 10:00:00', 'Europe/London'));

    expect(app(OverdueSubjects::class)->forTenant($tenant))->toHaveCount(1);
});
