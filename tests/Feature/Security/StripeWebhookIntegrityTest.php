<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\StripeEvent;
use App\Models\WebhookFailure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
    Mail::fake();
});

function pendingBooking(array $salon, string $intentId = 'pi_x', int $deposit = 1000): Booking
{
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    return Booking::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => Customer::factory()->create(['tenant_id' => $salon['tenant']->id])->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'status' => BookingStatus::Pending,
        'deposit_status' => DepositStatus::Required,
        'deposit_at_booking' => $deposit,
        'stripe_payment_intent_id' => $intentId,
        'source' => BookingSource::Online,
    ]);
}

function paymentEvent(array $object, string $id = 'evt_1', ?string $account = 'acct_test'): array
{
    $event = ['id' => $id, 'type' => 'payment_intent.succeeded', 'data' => ['object' => $object]];

    if ($account !== null) {
        $event['account'] = $account;
    }

    return $event;
}

function postWebhook(array $event): TestResponse
{
    return test()->postJson('/stripe/webhook', $event, ['Stripe-Signature' => 't=1,v1=test']);
}

it('confirms a booking when the account, the booking and the amount all agree', function () {
    $salon = aConnectedSalon(1000, 'acct_good');
    $booking = pendingBooking($salon, 'pi_good', 1000);

    postWebhook(paymentEvent([
        'id' => 'pi_good',
        'amount_received' => 1000,
        'currency' => 'gbp',
        'metadata' => ['booking_id' => (string) $booking->id],
    ], 'evt_good', 'acct_good'))->assertOk();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->fresh()->deposit_status)->toBe(DepositStatus::Paid);
});

it('refuses to confirm another tenant booking named in attacker-controlled metadata', function () {
    $victim = aConnectedSalon(1000, 'acct_victim');
    $attacker = aConnectedSalon(1000, 'acct_attacker');
    $booking = pendingBooking($victim, 'pi_victim', 1000);

    // The attacker controls their own connected account and can put any metadata on an intent.
    postWebhook(paymentEvent([
        'id' => 'pi_attacker',
        'amount_received' => 1,
        'currency' => 'gbp',
        'metadata' => ['booking_id' => (string) $booking->id],
    ], 'evt_attack', 'acct_attacker'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending)
        ->and($booking->fresh()->deposit_status)->toBe(DepositStatus::Required);
});

it('refuses to confirm when the amount received is short of the deposit', function () {
    $salon = aConnectedSalon(1000, 'acct_short');
    $booking = pendingBooking($salon, 'pi_short', 1000);

    postWebhook(paymentEvent([
        'id' => 'pi_short',
        'amount_received' => 1,
        'currency' => 'gbp',
        'metadata' => ['booking_id' => (string) $booking->id],
    ], 'evt_short', 'acct_short'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

it('refuses to confirm when the currency does not match the tenant', function () {
    $salon = aConnectedSalon(1000, 'acct_cur');
    $booking = pendingBooking($salon, 'pi_cur', 1000);

    postWebhook(paymentEvent([
        'id' => 'pi_cur',
        'amount_received' => 1000,
        'currency' => 'usd',
        'metadata' => ['booking_id' => (string) $booking->id],
    ], 'evt_cur', 'acct_cur'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

it('never guesses a booking when the event identifies none', function () {
    $salon = aConnectedSalon(1000, 'acct_blank');
    $booking = pendingBooking($salon, 'pi_blank', 1000);

    postWebhook(paymentEvent([
        'amount_received' => 1000,
        'currency' => 'gbp',
        'metadata' => [],
    ], 'evt_blank', 'acct_blank'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending)
        ->and(Booking::withoutGlobalScopes()->where('status', BookingStatus::Confirmed->value)->count())->toBe(0);
});

it('records a webhook failure when an event cannot be attributed', function () {
    $salon = aConnectedSalon(1000, 'acct_orphan');
    pendingBooking($salon, 'pi_orphan', 1000);

    postWebhook(paymentEvent([
        'id' => 'pi_nothing',
        'amount_received' => 1000,
        'currency' => 'gbp',
        'metadata' => ['booking_id' => '999999'],
    ], 'evt_orphan', 'acct_orphan'));

    expect(WebhookFailure::query()->where('source', 'connect')->count())->toBeGreaterThan(0);
});

it('refuses to confirm a refund against a booking on a different connected account', function () {
    $victim = aConnectedSalon(1000, 'acct_rv');
    $booking = pendingBooking($victim, 'pi_rv', 1000);
    $booking->forceFill(['deposit_status' => DepositStatus::Paid, 'status' => BookingStatus::Confirmed])->save();

    postWebhook([
        'id' => 'evt_refund',
        'type' => 'charge.refunded',
        'account' => 'acct_someone_else',
        'data' => ['object' => ['payment_intent' => 'pi_rv']],
    ]);

    expect($booking->fresh()->deposit_status)->toBe(DepositStatus::Paid);
});

it('asks stripe to retry when the event cannot be stored', function () {
    Schema::drop('stripe_events');

    postWebhook(paymentEvent(['id' => 'pi_x', 'metadata' => []], 'evt_storage_fail'))
        ->assertStatus(500);
});

it('still acknowledges a duplicate event without dispatching twice', function () {
    $salon = aConnectedSalon(1000, 'acct_dup');
    $booking = pendingBooking($salon, 'pi_dup', 1000);

    $event = paymentEvent([
        'id' => 'pi_dup',
        'amount_received' => 1000,
        'currency' => 'gbp',
        'metadata' => ['booking_id' => (string) $booking->id],
    ], 'evt_dup2', 'acct_dup');

    postWebhook($event)->assertOk();
    postWebhook($event)->assertOk();

    expect(StripeEvent::query()->where('event_id', 'evt_dup2')->count())->toBe(1);
});
