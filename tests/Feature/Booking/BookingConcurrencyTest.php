<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Exceptions\SlotUnavailableException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use Carbon\CarbonImmutable;

function bookableSalon(): array
{
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'slug' => 'willow-street-grooming',
    ]);
    $staff = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_bookable' => true,
        'is_active' => true,
    ]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'is_active' => true,
        'price' => 3500,
        'deposit_amount' => 1000,
    ]);
    $service->staff()->attach($staff->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    return compact('tenant', 'staff', 'service');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

it('creates an unconnected online booking as confirmed with no deposit', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = bookableSalon();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $booking = app(BookingService::class)->create(
        $tenant,
        $service,
        $staff,
        $customer,
        $startsAt,
        BookingSource::Online,
    );

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->deposit_status->value)->toBe('none')
        ->and($booking->source)->toBe(BookingSource::Online)
        ->and($booking->public_token)->not->toBeEmpty()
        ->and($booking->stripe_payment_intent_id)->toBeNull();
});

it('rejects a second booking for the same slot after a competing insert inside the lock', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = bookableSalon();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $bookings = app(BookingService::class)->withAfterLock(function () use ($tenant, $staff, $service, $startsAt) {
        Booking::factory()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'customer_id' => Customer::factory()->create(['tenant_id' => $tenant->id])->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'status' => BookingStatus::Confirmed,
            'source' => BookingSource::Manual,
        ]);
    });

    expect(fn () => $bookings->create(
        $tenant,
        $service,
        $staff,
        $customer,
        $startsAt,
        BookingSource::Online,
    ))->toThrow(SlotUnavailableException::class);
});

it('returns 409 when two public requests try to take the same slot', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = bookableSalon();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $payload = [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $startsAt->toIso8601String(),
        'name' => 'Alex Reed',
        'email' => 'alex@example.com',
        'phone' => '07700900000',
        'subject_name' => 'Willow',
        'subject_attributes' => [
            'breed' => 'Labrador',
            'size' => 'medium',
        ],
    ];

    $this->postJson(route('public.booking.store', $tenant->slug), $payload)
        ->assertCreated()
        ->assertJsonPath('booking.status', 'confirmed')
        ->assertJsonPath('booking.deposit_status', 'none')
        ->assertJsonPath('payment', null);

    $this->postJson(route('public.booking.store', $tenant->slug), [
        ...$payload,
        'email' => 'other@example.com',
        'name' => 'Jamie Cole',
    ])->assertStatus(409);

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});
