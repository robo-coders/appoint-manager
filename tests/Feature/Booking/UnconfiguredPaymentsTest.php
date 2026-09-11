<?php

use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\Booking;
use App\Models\StripeEvent;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

function withNoPaymentsConfigured(): void
{
    app()['env'] = 'local';

    config([
        'services.stripe.key' => null,
        'services.stripe.secret' => null,
        'services.stripe.webhook_secret' => null,
    ]);

    app()->forgetInstance(StripeGateway::class);
}

function bookWithNoPayments(array $salon, string $email = 'alex@example.com'): TestResponse
{
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    return test()
        ->withSession(['_token' => 'a-real-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'a-real-csrf-token')
        ->postJson(
            route('public.booking.store', $salon['tenant']->slug),
            aBookingPayload($salon['service'], $salon['staff'], $startsAt, $email),
        );
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
    Mail::fake();
});

it('takes a booking for a salon that asks for no deposit', function () {
    $salon = aSalon();

    withNoPaymentsConfigured();

    $response = bookWithNoPayments($salon);

    $response->assertCreated();

    expect($response->json('booking.status'))->toBe(BookingStatus::Confirmed->value)
        ->and($response->json('booking.deposit_status'))->toBe(DepositStatus::None->value)
        ->and($response->json('payment'))->toBeNull();
});

it('does not fail at container resolution', function () {
    $salon = aSalon();

    withNoPaymentsConfigured();

    bookWithNoPayments($salon)->assertStatus(201);
});

it('answers a deposit-taking salon with a sentence rather than a stack trace', function () {
    $salon = aConnectedSalon(1000, 'acct_unconfigured');

    withNoPaymentsConfigured();

    $response = bookWithNoPayments($salon);

    $response->assertStatus(503);

    expect($response->json('message'))
        ->toContain('nothing has been')
        ->toContain('call the salon');
});

it('releases the slot it could not take a deposit for', function () {
    $salon = aConnectedSalon(1000, 'acct_unconfigured2');

    withNoPaymentsConfigured();

    bookWithNoPayments($salon)->assertStatus(503);

    $live = Booking::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->where('status', '!=', BookingStatus::Cancelled->value)
        ->count();

    expect($live)->toBe(0, 'a hold nobody can pay for was left on the diary');
});

it('still refuses to hand out a gateway with no credentials', function () {
    withNoPaymentsConfigured();

    expect(fn () => app(StripeGateway::class))
        ->toThrow(RuntimeException::class, 'STRIPE_SECRET')
        ->and(fn () => app(StripeGateway::class))->not->toBeInstanceOf(FakeStripeGateway::class);
});

it('still refuses a forged webhook signature with no credentials', function () {
    withNoPaymentsConfigured();

    $response = $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 't=1,v1=test',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode([
        'id' => 'evt_forged',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['metadata' => ['booking_id' => '1']]],
    ]));

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400,
        'the webhook accepted a forged signature on a machine with no Stripe secret');

    expect(StripeEvent::withoutGlobalScopes()->count())->toBe(0);
});
