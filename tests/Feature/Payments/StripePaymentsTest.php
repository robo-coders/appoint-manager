<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\Weekday;
use App\Exceptions\PaymentSetupFailedException;
use App\Mail\BookingConfirmedMail;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StripeEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

function paymentSalon(array $service = []): array
{
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'stripe_account_id' => 'acct_test',
        'stripe_onboarding_complete' => true,
        'country' => 'GB',
    ]);
    app(StripeGateway::class)->completeAccount('acct_test');

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
        'deposit_amount' => $service['deposit_amount'] ?? 1000,
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

function publicPayload(Service $service, User $staff, CarbonImmutable $startsAt, string $email = 'alex@example.com'): array
{
    return [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $startsAt->toIso8601String(),
        'name' => 'Alex Reed',
        'email' => $email,
        'phone' => '07700900000',
        'subject_name' => 'Willow',
        'subject_attributes' => ['breed' => 'Labrador', 'size' => 'medium'],
    ];
}

function stripeWebhook(array $event): TestResponse
{
    return test()->postJson('/stripe/webhook', $event, ['Stripe-Signature' => 't=1,v1=test']);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

it('books a deposit-free service straight to confirmed', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = paymentSalon(['deposit_amount' => 0]);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), publicPayload($service, $staff, $startsAt))
        ->assertCreated()
        ->assertJsonPath('booking.status', 'confirmed')
        ->assertJsonPath('booking.deposit_status', 'none')
        ->assertJsonPath('payment', null);

    expect(app(StripeGateway::class)->intents)->toBe([]);
});

it('books an unconnected tenant straight to confirmed with no payment attempt', function () {
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'stripe_onboarding_complete' => false,
        'stripe_account_id' => null,
    ]);
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'deposit_amount' => 1000,
        'duration_minutes' => 60,
        'is_active' => true,
    ]);
    $service->staff()->attach($staff->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), publicPayload($service, $staff, $startsAt))
        ->assertCreated()
        ->assertJsonPath('booking.status', 'confirmed')
        ->assertJsonPath('payment', null);

    expect(app(StripeGateway::class)->intents)->toBe([]);
});

it('rejects a webhook with a bad signature', function () {
    $this->postJson('/stripe/webhook', ['id' => 'evt_x', 'type' => 'payment_intent.succeeded'], [
        'Stripe-Signature' => 'nope',
    ])->assertStatus(400);
});

it('processes a duplicate event id once', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = paymentSalon();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), publicPayload($service, $staff, $startsAt))->assertCreated();
    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

    $event = [
        'id' => 'evt_dup',
        'type' => 'payment_intent.succeeded',
        'account' => $tenant->stripe_account_id,
        'data' => [
            'object' => [
                'id' => $booking->stripe_payment_intent_id,
                'amount_received' => $booking->deposit_at_booking->amount,
                'currency' => 'gbp',
                'metadata' => ['booking_id' => (string) $booking->id],
            ],
        ],
    ];

    stripeWebhook($event)->assertOk();
    stripeWebhook($event)->assertOk();

    expect(StripeEvent::query()->where('event_id', 'evt_dup')->count())->toBe(1)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    Mail::assertQueued(BookingConfirmedMail::class, 1);
});

it('releases a pending booking after the checkout hold expires', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = paymentSalon();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $this->postJson(route('public.booking.store', $tenant->slug), publicPayload($service, $staff, $startsAt))->assertCreated();

    $this->travel(16)->minutes();
    $this->artisan('bookings:release-expired')->assertSuccessful();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
});

it('refunds a paid deposit outside the window and keeps it inside', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = paymentSalon();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+447700900000']);

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
        'stripe_payment_intent_id' => 'pi_outside',
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
        'stripe_payment_intent_id' => 'pi_inside',
        'source' => BookingSource::Online,
    ]);

    $bookings = app(BookingService::class);
    $bookings->cancel($far, 'customer', false);
    $bookings->cancel($near, 'customer', false);

    expect($far->fresh()->deposit_status)->toBe(DepositStatus::Refunded)
        ->and($near->fresh()->deposit_status)->toBe(DepositStatus::Paid)
        ->and(app(StripeGateway::class)->refunds)->toContain('pi_outside')
        ->and(app(StripeGateway::class)->refunds)->not->toContain('pi_inside');
});

it('releases the hold instead of leaving an unpayable pending booking', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = paymentSalon();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+447700900000']);

    $gateway = app(StripeGateway::class);
    $gateway->throwOnCreate = true;

    // The booking row is committed before Stripe is called — that ordering is what
    // keeps the row lock off the network — but a booking nobody can pay for is not
    // left behind pretending to be a live hold.
    expect(fn () => app(BookingService::class)->create(
        $tenant, $service, $staff, $customer, $startsAt, BookingSource::Online,
    ))->toThrow(PaymentSetupFailedException::class);

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    expect($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancellation_reason)->toBe('payment_setup_failed')
        ->and($booking->stripe_payment_intent_id)->toBeNull()
        ->and($gateway->intents)->toBe([]);
});
