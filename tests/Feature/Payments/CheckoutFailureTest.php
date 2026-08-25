<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
    Mail::fake();
});

it('tells the customer plainly when payments are unreachable', function () {
    $salon = aConnectedSalon(1000, 'acct_down');
    app(StripeGateway::class)->throwOnCreate = true;
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $response = $this->postJson(
        route('public.booking.store', $salon['tenant']->slug),
        aBookingPayload($salon['service'], $salon['staff'], $startsAt),
    );

    $response->assertStatus(503);
    expect($response->json('message'))->toContain('nothing has been charged');
});

it('releases the slot when the payment intent cannot be created', function () {
    $salon = aConnectedSalon(1000, 'acct_down2');
    app(StripeGateway::class)->throwOnCreate = true;
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(
        route('public.booking.store', $salon['tenant']->slug),
        aBookingPayload($salon['service'], $salon['staff'], $startsAt),
    )->assertStatus(503);

    $live = Booking::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->where('status', '!=', BookingStatus::Cancelled->value)
        ->count();

    expect($live)->toBe(0);
});

it('lets the next customer take the slot after a failed checkout', function () {
    $salon = aConnectedSalon(1000, 'acct_down3');
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    app(StripeGateway::class)->throwOnCreate = true;
    $this->postJson(
        route('public.booking.store', $salon['tenant']->slug),
        aBookingPayload($salon['service'], $salon['staff'], $startsAt),
    )->assertStatus(503);

    app(StripeGateway::class)->throwOnCreate = false;
    $this->postJson(
        route('public.booking.store', $salon['tenant']->slug),
        aBookingPayload($salon['service'], $salon['staff'], $startsAt, 'someone-else@example.com'),
    )->assertCreated();
});

it('always returns a client secret alongside a pending deposit booking', function () {
    $salon = aConnectedSalon(1000, 'acct_ok');
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $response = $this->postJson(
        route('public.booking.store', $salon['tenant']->slug),
        aBookingPayload($salon['service'], $salon['staff'], $startsAt),
    )->assertCreated();

    expect($response->json('booking.status'))->toBe('pending')
        ->and($response->json('payment.client_secret'))->not->toBeEmpty();
});
