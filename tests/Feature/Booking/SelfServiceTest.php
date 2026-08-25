<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Stripe\StripeGateway;
use Carbon\CarbonImmutable;

function manageSalon(): array
{
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'stripe_account_id' => 'acct_manage',
        'stripe_onboarding_complete' => true,
    ]);
    app(StripeGateway::class)->completeAccount('acct_manage');
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'is_active' => true,
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

it('reschedules atomically, freeing the old slot and taking the new one', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = manageSalon();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $old = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $new = CarbonImmutable::parse('2026-03-10 11:00:00', 'Europe/London')->utc();

    $booking = app(BookingService::class)->create(
        $tenant, $service, $staff, $customer, $old, BookingSource::Manual,
    );

    $this->postJson(route('booking.manage.reschedule', $booking->public_token), [
        'starts_at' => $new->toIso8601String(),
        'staff_id' => $staff->id,
    ])->assertOk();

    expect($booking->fresh()->starts_at->eq($new))->toBeTrue();

    $other = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $takenOld = app(BookingService::class)->create(
        $tenant, $service, $staff, $other, $old, BookingSource::Manual,
    );
    expect($takenOld->starts_at->eq($old))->toBeTrue();
});

it('fails cleanly when rescheduling to an unavailable slot', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = manageSalon();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $old = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $blocked = CarbonImmutable::parse('2026-03-10 11:00:00', 'Europe/London')->utc();

    $booking = app(BookingService::class)->create($tenant, $service, $staff, $customer, $old, BookingSource::Manual);
    app(BookingService::class)->create(
        $tenant,
        $service,
        $staff,
        Customer::factory()->create(['tenant_id' => $tenant->id]),
        $blocked,
        BookingSource::Manual,
    );

    $this->postJson(route('booking.manage.reschedule', $booking->public_token), [
        'starts_at' => $blocked->toIso8601String(),
        'staff_id' => $staff->id,
    ])->assertStatus(409);

    expect($booking->fresh()->starts_at->eq($old))->toBeTrue();
});

it('cancels with a refund outside the window and without one inside it', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = manageSalon();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $outside = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $inside = CarbonImmutable::parse('2026-03-02 09:00:00', 'Europe/London')->utc();

    $far = Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $staff->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $outside,
        'ends_at' => $outside->addHour(),
        'status' => BookingStatus::Confirmed,
        'deposit_status' => DepositStatus::Paid,
        'deposit_at_booking' => 1000,
        'stripe_payment_intent_id' => 'pi_far',
        'source' => BookingSource::Online,
    ]);
    $near = Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $staff->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $inside,
        'ends_at' => $inside->addHour(),
        'status' => BookingStatus::Confirmed,
        'deposit_status' => DepositStatus::Paid,
        'deposit_at_booking' => 1000,
        'stripe_payment_intent_id' => 'pi_near',
        'source' => BookingSource::Online,
    ]);

    $this->get(route('booking.manage.show', $far->public_token))
        ->assertOk()
        ->assertSee('Your deposit will be refunded', false);

    $this->postJson(route('booking.manage.cancel', $far->public_token))->assertOk();
    $this->postJson(route('booking.manage.cancel', $near->public_token))->assertOk();

    expect($far->fresh()->deposit_status)->toBe(DepositStatus::Refunded)
        ->and($near->fresh()->deposit_status)->toBe(DepositStatus::Paid)
        ->and($far->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('returns 404 for an invalid or tampered public token', function () {
    $this->get('/b/not-a-real-token')->assertNotFound();
    $this->postJson('/b/'.str_repeat('a', 36).'/cancel')->assertNotFound();
});
