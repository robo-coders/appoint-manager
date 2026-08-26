<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Jobs\ProcessStripeEvent;
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

/*
 * AUDIT C2, the part the cases above do not reach: `account.updated` is the
 * event that flips `stripe_onboarding_complete`, which is what decides whether
 * a salon can take deposits at all. A connected account may only ever speak for
 * itself.
 */
it('refuses an account.updated that speaks for a different connected account', function () {
    $salon = aConnectedSalon(1000, 'acct_victim');
    $salon['tenant']->forceFill(['stripe_onboarding_complete' => false])->save();

    postWebhook([
        'id' => 'evt_account_forged',
        'type' => 'account.updated',
        'account' => 'acct_attacker',
        'data' => ['object' => ['id' => 'acct_victim', 'charges_enabled' => true]],
    ])->assertOk();

    ProcessStripeEvent::dispatchSync(StripeEvent::query()->latest('id')->first()->id);

    expect($salon['tenant']->fresh()->stripe_onboarding_complete)->toBeFalse();
    expect(WebhookFailure::query()->where('event_id', 'evt_account_forged')->exists())->toBeTrue();
});

/*
 * Everything that gets written is re-derived from our own rows by id. The
 * payload is a claim: a metadata block naming a tenant, an amount and a status
 * of its own must change none of them.
 */
it('ignores every field in metadata except the booking id it has to verify', function () {
    $salon = aConnectedSalon(1000, 'acct_good');
    $other = aConnectedSalon(1000, 'acct_other');
    $booking = pendingBooking($salon, 'pi_good', 1000);

    postWebhook(paymentEvent([
        'id' => 'pi_good',
        'amount_received' => 1000,
        'currency' => 'gbp',
        'metadata' => [
            'booking_id' => (string) $booking->id,
            // All of this is attacker-controlled and none of it may be believed.
            'tenant_id' => (string) $other['tenant']->id,
            'deposit_at_booking' => '1',
            'status' => 'completed',
            'price_at_booking' => '1',
        ],
    ], 'evt_metadata_noise', 'acct_good'))->assertOk();

    ProcessStripeEvent::dispatchSync(StripeEvent::query()->latest('id')->first()->id);

    $booking->refresh();

    expect($booking->tenant_id)->toBe($salon['tenant']->id)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->deposit_at_booking->amount)->toBe(1000)
        ->and($booking->price_at_booking->amount)->toBeGreaterThan(1);
});

/*
 * The event is stored with the account that sent it, so an attribution can be
 * checked after the fact rather than argued about.
 */
it('records which connected account sent each event', function () {
    $salon = aConnectedSalon(1000, 'acct_good');
    pendingBooking($salon, 'pi_good', 1000);

    postWebhook(paymentEvent(['id' => 'pi_good', 'amount_received' => 1000], 'evt_audit', 'acct_good'));

    expect(StripeEvent::query()->where('event_id', 'evt_audit')->value('account_id'))->toBe('acct_good');
});
