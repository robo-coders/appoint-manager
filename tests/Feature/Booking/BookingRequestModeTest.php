<?php

use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\MessageType;
use App\Enums\PreferredTime;
use App\Enums\SlotOfferStatus;
use App\Mail\BookingConfirmedMail;
use App\Mail\BookingDeclinedMail;
use App\Mail\SalonNewRequestMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\SlotOffer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Booking\BookingService;
use App\Services\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

function requestSalon(array $tenant = [], array $service = []): array
{
    return aSalon([
        'tenant' => array_merge([
            'booking_mode' => BookingMode::Request,
            'request_requires_deposit' => true,
            'phone' => '07700900111',
            'email' => 'salon@example.com',
        ], $tenant),
        'service' => $service,
    ]);
}

function connectedRequestSalon(bool $requireDeposit = true, int $deposit = 1000): array
{
    return aConnectedSalon($deposit, 'acct_request');
}

it('leaves automated tenants on the existing confirmed path with no payment', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = aSalon();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated()
        ->assertJsonPath('booking.status', 'confirmed')
        ->assertJsonPath('payment', null);

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    expect($tenant->fresh()->booking_mode)->toBe(BookingMode::Automated)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->request_expires_at)->toBeNull()
        ->and(app(StripeGateway::class)->intents)->toBe([]);
});

it('leaves automated deposit tenants on the existing pending checkout path', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = aConnectedSalon();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated()
        ->assertJsonPath('booking.status', 'pending')
        ->assertJsonPath('payment.client_secret', 'pi_fake_1_secret_test');

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    expect($booking->request_expires_at)->toBeNull()
        ->and($booking->deposit_status)->toBe(DepositStatus::Required)
        ->and(app(StripeGateway::class)->intents[0]['capture_method'])->toBe('automatic');

    Mail::assertNotQueued(SalonNewRequestMail::class);
});

it('creates a pending request that locks the slot against another booking', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = requestSalon(
        ['stripe_account_id' => null, 'stripe_onboarding_complete' => false],
        ['deposit_amount' => 0],
    );
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated()
        ->assertJsonPath('booking.status', 'pending')
        ->assertJsonPath('payment', null);

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->request_expires_at)->toEqual(now()->addHours(24))
        ->and($booking->occupiesTime())->toBeTrue();

    $this->postJson(
        route('public.booking.store', $tenant->slug),
        aBookingPayload($service, $staff, $startsAt, 'other@example.com'),
    )->assertStatus(409);

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('authorises a deposit on request and captures it on approve', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = connectedRequestSalon();
    $tenant->forceFill([
        'booking_mode' => BookingMode::Request,
        'request_requires_deposit' => true,
        'phone' => '07700900111',
        'email' => 'salon@example.com',
    ])->save();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $owner = $staff;

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated()
        ->assertJsonPath('booking.status', 'pending');

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    $gateway = app(StripeGateway::class);

    expect($gateway->intents[0]['capture_method'])->toBe('manual')
        ->and($booking->deposit_status)->toBe(DepositStatus::Required)
        ->and($booking->stripe_payment_intent_id)->toBe('pi_fake_1');

    Mail::assertQueued(SalonNewRequestMail::class, 1);
    Mail::assertNotQueued(BookingConfirmedMail::class);

    actingAsTenant($owner)
        ->post(route('bookings.approve', $booking))
        ->assertRedirect();

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->deposit_status)->toBe(DepositStatus::Paid)
        ->and($booking->request_expires_at)->toBeNull()
        ->and($gateway->captures)->toContain('pi_fake_1')
        ->and(AuditLog::query()->where('action', 'booking.request.approved')->where('meta->booking_id', $booking->id)->count())->toBe(1);

    Mail::assertQueued(BookingConfirmedMail::class, 1);
});

it('voids the held payment on decline, frees the slot, and offers the waitlist', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = connectedRequestSalon();
    $tenant->forceFill([
        'booking_mode' => BookingMode::Request,
        'request_requires_deposit' => true,
        'phone' => '07700900111',
        'email' => 'salon@example.com',
    ])->save();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $waiting = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'phone' => '+447700900222',
        'email' => 'wait@example.com',
    ]);
    WaitlistEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $waiting->id,
        'service_id' => $service->id,
        'preferred_days' => [2],
        'preferred_times' => PreferredTime::Any,
        'is_active' => true,
    ]);

    actingAsTenant($staff)
        ->post(route('bookings.decline', $booking), ['reason' => 'Fully booked that morning'])
        ->assertRedirect();

    $booking->refresh();
    $gateway = app(StripeGateway::class);

    expect($booking->status)->toBe(BookingStatus::Declined)
        ->and($booking->occupiesTime())->toBeFalse()
        ->and($gateway->cancels)->toContain('pi_fake_1')
        ->and($gateway->captures)->toBe([])
        ->and(SlotOffer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', SlotOfferStatus::Sent->value)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'booking.request.declined')->count())->toBe(1);

    Mail::assertQueued(BookingDeclinedMail::class, 1);

    $this->postJson(
        route('public.booking.store', $tenant->slug),
        aBookingPayload($service, $staff, $startsAt, 'later@example.com'),
    )->assertCreated();
});

it('expires stale requests the same way as a manual decline', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = connectedRequestSalon();
    $tenant->forceFill([
        'booking_mode' => BookingMode::Request,
        'request_requires_deposit' => true,
        'email' => 'salon@example.com',
    ])->save();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $waiting = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'phone' => '+447700900333',
        'email' => 'wait2@example.com',
    ]);
    WaitlistEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $waiting->id,
        'service_id' => $service->id,
        'preferred_days' => [2],
        'preferred_times' => PreferredTime::Any,
        'is_active' => true,
    ]);

    $this->travel(25)->hours();
    $this->artisan('bookings:expire-requests')->assertSuccessful();

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Declined)
        ->and($booking->occupiesTime())->toBeFalse()
        ->and(app(StripeGateway::class)->cancels)->toContain('pi_fake_1')
        ->and(SlotOffer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'booking.request.expired')->count())->toBe(1);

    Mail::assertQueued(BookingDeclinedMail::class, 1);
});

it('does not take a payment when request_requires_deposit is off', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = aConnectedSalon(1000);
    $tenant->forceFill([
        'booking_mode' => BookingMode::Request,
        'request_requires_deposit' => false,
        'email' => 'salon@example.com',
    ])->save();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated()
        ->assertJsonPath('booking.status', 'pending')
        ->assertJsonPath('booking.deposit_status', 'none')
        ->assertJsonPath('payment', null);

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    expect($booking->deposit_at_booking->amount)->toBe(0)
        ->and($booking->stripe_payment_intent_id)->toBeNull()
        ->and(app(StripeGateway::class)->intents)->toBe([]);

    actingAsTenant($staff)->post(route('bookings.approve', $booking))->assertRedirect();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->fresh()->deposit_status)->toBe(DepositStatus::None)
        ->and(app(StripeGateway::class)->captures)->toBe([]);
});

it('does not let the checkout-hold job cancel a request-mode pending booking', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = requestSalon(
        [],
        ['deposit_amount' => 0],
    );
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated();

    $this->travel(16)->minutes();
    $this->artisan('bookings:release-expired')->assertSuccessful();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->status)
        ->toBe(BookingStatus::Pending);
});

it('does not confirm a request from the payment webhook', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = connectedRequestSalon();
    $tenant->forceFill([
        'booking_mode' => BookingMode::Request,
        'request_requires_deposit' => true,
    ])->save();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated();

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $this->postJson('/stripe/webhook', [
        'id' => 'evt_request_skip',
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
    ], ['Stripe-Signature' => 't=1,v1=test'])->assertOk();

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

it('creates a pending request from a rebooking-style online booking', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = requestSalon(
        [],
        ['deposit_amount' => 0],
    );
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $booking = app(BookingService::class)->create(
        $tenant->fresh(),
        $service,
        $staff,
        Customer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+447700900444']),
        $startsAt,
        BookingSource::Online,
    );

    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->request_expires_at)->not->toBeNull()
        ->and($booking->source)->toBe(BookingSource::Online);
});

it('saves booking mode on onboarding and settings', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete())
        ->create();

    actingAsTenant($user)
        ->patch(route('onboarding.business'), [
            'timezone' => 'Europe/London',
            'booking_mode' => 'request',
            'request_requires_deposit' => false,
        ])
        ->assertRedirect();

    expect($user->tenant->fresh()->booking_mode)->toBe(BookingMode::Request)
        ->and($user->tenant->fresh()->request_requires_deposit)->toBeFalse();

    $user->tenant->forceFill(['onboarding_completed_at' => now()])->save();

    actingAsTenant($user->fresh())
        ->patch(route('settings.update'), [
            'name' => $user->tenant->name,
            'timezone' => 'Europe/London',
            'booking_mode' => 'automated',
            'request_requires_deposit' => true,
        ])
        ->assertRedirect();

    expect($user->tenant->fresh()->booking_mode)->toBe(BookingMode::Automated)
        ->and($user->tenant->fresh()->request_requires_deposit)->toBeTrue();
});

it('lists pending requests on the dashboard with approve and decline', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = requestSalon(
        [],
        ['deposit_amount' => 0],
    );
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated();

    actingAsTenant($staff)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('pending_requests', 1)
            ->where('pending_requests.0.service', $service->name));
});

it('uses request copy on the public booking page', function () {
    ['tenant' => $tenant] = requestSalon([], ['deposit_amount' => 0]);

    $html = $this->get(route('public.booking.show', $tenant->slug))->assertOk()->getContent();
    preg_match('/id="booking-props">(?<json>.*?)<\/script>/s', $html, $matches);
    $props = json_decode(html_entity_decode($matches['json'], ENT_QUOTES), true);

    expect($props['tenant']['booking_mode'])->toBe('request')
        ->and($props['tenant']['request_sent_message'])->toContain($tenant->name)
        ->and($props['tenant']['request_sent_message'])->toContain('a day')
        ->and($props['suggestion']['primary']['action_label'])->toBe('Request this time');
});

it('texts the salon when a request arrives', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = requestSalon(
        ['phone' => '07700900111'],
        ['deposit_amount' => 0],
    );
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), aBookingPayload($service, $staff, $startsAt))
        ->assertCreated();

    $sms = Message::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('type', MessageType::BookingRequested)
        ->where('channel', 'sms')
        ->first();

    expect($sms)->not->toBeNull()
        ->and($sms->body)->toContain('request')
        ->and($sms->to)->toBe('+447700900111');
});
