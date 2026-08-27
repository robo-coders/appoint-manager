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

/**
 * A machine with no Stripe credentials.
 *
 * `PublicBookingController::store` type-hints `BookingService`, which used to
 * type-hint `StripeGateway`, whose binding refuses to resolve without
 * credentials (AUDIT C1 — the alternative is a fake gateway that accepts forged
 * webhook signatures). The refusal therefore happened while the container was
 * building the controller's arguments: the POST died before a line of its own
 * code ran, for **every** tenant on the page, whether or not the booking
 * involved money. A salon that takes no deposits got a stack trace out of a
 * code path that never needed a gateway at all.
 *
 * A tenant with no Stripe account is a normal state. It gets a normal booking.
 * A platform with no Stripe credentials is not normal, and it gets a sentence.
 * Neither gets a 500.
 *
 * The suite runs under `testing`, where the fake gateway is bound and always
 * resolves, so these tests have to leave that environment to see the bug at
 * all — which is the same reason it was found in a browser and not here.
 */
function withNoPaymentsConfigured(): void
{
    app()['env'] = 'local';

    config([
        'services.stripe.key' => null,
        'services.stripe.secret' => null,
        'services.stripe.webhook_secret' => null,
    ]);

    // The singleton was already built under `testing`. Drop it, so the next
    // resolution asks the binding the question this test is about.
    app()->forgetInstance(StripeGateway::class);
}

/**
 * Book, on that machine.
 *
 * Carrying a CSRF token by hand because leaving `testing` also leaves the
 * bypass that lets the rest of the suite post without one — the token is real
 * and the middleware really checks it, which is what the booking island does
 * too. Disabling `ValidateCsrfToken` would have been the shorter route and
 * would have quietly changed what these tests cover.
 */
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

/*
 * The one that names the bug. Before the fix this was a 500 with a stack trace,
 * and it was a 500 for the no-deposit salon above as well.
 */
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

    // Not "try again in a moment": nothing is coming back for this customer.
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

/*
|--------------------------------------------------------------------------
| C1 is unchanged
|--------------------------------------------------------------------------
|
| The fix moves *where* the container's refusal is asked for. It must not move
| whether the refusal happens, and it must not make the fake gateway reachable
| by the one route the fake would have exposed.
|
*/

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
