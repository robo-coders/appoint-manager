<?php

use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\BillingEvent;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

it('locks admin writes when the trial expires but keeps public booking working', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'trial_ends_at' => now()->subDay(),
        'subscription_status' => 'trial',
        'booking_page_live' => true,
    ]);
    $owner = User::factory()->for($tenant)->owner()->create(['is_bookable' => true]);
    $service = Service::factory()->for($tenant)->create(['duration_minutes' => 60]);
    $service->staff()->attach($owner->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    actingAsTenant($owner)
        ->get(route('diary.index'))
        ->assertOk();

    actingAsTenant($owner)
        ->post(route('bookings.store'), [
            'service_id' => $service->id,
            'staff_id' => $owner->id,
            'starts_at' => '2026-03-10T11:00',
            'customer_name' => 'Sam Lee',
            'customer_email' => 'sam@example.com',
        ])
        ->assertRedirect();

    expect(Booking::query()->count())->toBe(0);

    $this->get(route('public.booking.show', $tenant->slug))->assertOk();
});

it('restores writes after a platform billing webhook and ignores duplicates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'trial_ends_at' => now()->subDay(),
        'subscription_status' => 'trial',
    ]);
    $owner = User::factory()->for($tenant)->owner()->create(['is_bookable' => true]);
    $service = Service::factory()->for($tenant)->create(['duration_minutes' => 60]);
    $service->staff()->attach($owner->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    $payload = json_encode([
        'id' => 'evt_billing_1',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'customer' => 'cus_platform',
                'subscription' => 'sub_platform',
                'metadata' => ['tenant_id' => (string) $tenant->id, 'interval' => 'monthly'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('stripe.billing.webhook'), [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_billing',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    $this->call('POST', route('stripe.billing.webhook'), [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_billing',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect(BillingEvent::query()->count())->toBe(1)
        ->and($tenant->fresh()->subscription_status)->toBe('active')
        ->and($tenant->fresh()->hasAdminWriteAccess())->toBeTrue();

    actingAsTenant($owner)
        ->post(route('bookings.store'), [
            'service_id' => $service->id,
            'staff_id' => $owner->id,
            'starts_at' => '2026-03-10T11:00',
            'customer_name' => 'Sam Lee',
            'customer_email' => 'sam@example.com',
        ])
        ->assertRedirect();

    expect(Booking::query()->count())->toBe(1);
});
