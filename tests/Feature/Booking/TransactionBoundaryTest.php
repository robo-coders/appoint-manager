<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Mail\BookingConfirmedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\Booking\BookingService;
use App\Services\Sms\SmsGateway;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Records the open transaction depth at the moment it is asked to send. */
class TransactionWatchingSms implements SmsGateway
{
    /** @var list<int> */
    public array $levels = [];

    public bool $throw = false;

    public function send(string $toE164, string $body): string
    {
        $this->levels[] = DB::transactionLevel();

        if ($this->throw) {
            throw new RuntimeException('Twilio is down.');
        }

        return 'spy-'.count($this->levels);
    }
}

/** Records the open transaction depth at the moment it is asked to move money. */
class TransactionWatchingStripe implements StripeGateway
{
    /** @var list<int> */
    public array $refundLevels = [];

    /** @var list<int> */
    public array $intentLevels = [];

    public bool $throwOnCreate = false;

    public function __construct(private FakeStripeGateway $inner = new FakeStripeGateway) {}

    public array $refunds = [];

    public function completeAccount(string $accountId): void
    {
        $this->inner->completeAccount($accountId);
    }

    public function createExpressAccount(App\Models\Tenant $tenant): string
    {
        return $this->inner->createExpressAccount($tenant);
    }

    public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        return $this->inner->createAccountLink($accountId, $returnUrl, $refreshUrl);
    }

    public function retrieveAccount(string $accountId): array
    {
        return $this->inner->retrieveAccount($accountId);
    }

    public function createPaymentIntent(App\Models\Tenant $tenant, Booking $booking): array
    {
        $this->intentLevels[] = DB::transactionLevel();
        $this->inner->throwOnCreate = $this->throwOnCreate;

        return $this->inner->createPaymentIntent($tenant, $booking);
    }

    public function refundPaymentIntent(string $paymentIntentId, string $accountId): string
    {
        $this->refundLevels[] = DB::transactionLevel();
        $this->refunds[] = $paymentIntentId;

        return $this->inner->refundPaymentIntent($paymentIntentId, $accountId);
    }

    public function constructEvent(string $payload, string $signature): array
    {
        return $this->inner->constructEvent($payload, $signature);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
    // RefreshDatabase wraps each test in a transaction, so "no transaction of our
    // own is open" means the depth is back at this baseline, not literally zero.
    $this->baseline = DB::transactionLevel();
    $this->sms = new TransactionWatchingSms;
    $this->stripe = new TransactionWatchingStripe;
    app()->instance(SmsGateway::class, $this->sms);
    app()->instance(StripeGateway::class, $this->stripe);
});

function paidBooking(array $salon, CarbonImmutable $startsAt, string $intent = 'pi_tx'): Booking
{
    return Booking::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => Customer::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'phone' => '+447700900000',
        ])->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'status' => BookingStatus::Confirmed,
        'deposit_status' => DepositStatus::Paid,
        'deposit_at_booking' => 1000,
        'stripe_payment_intent_id' => $intent,
        'source' => BookingSource::Online,
    ]);
}

it('never sends an SMS while a database transaction is open', function () {
    Mail::fake();
    $salon = aSalon(['tenant' => ['stripe_account_id' => 'acct_tx', 'stripe_onboarding_complete' => true]]);
    $this->stripe->completeAccount('acct_tx');
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'phone' => '+447700900000']);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    app(BookingService::class)->create(
        $salon['tenant'], $salon['service'], $salon['staff'], $customer, $startsAt, BookingSource::Manual,
    );

    expect($this->sms->levels)->not->toBeEmpty()
        ->and($this->sms->levels)->each->toBe($this->baseline);
});

it('queues booking mail rather than sending it inline', function () {
    Mail::fake();
    $salon = aSalon();
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'phone' => '+447700900000']);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    app(BookingService::class)->create(
        $salon['tenant'], $salon['service'], $salon['staff'], $customer, $startsAt, BookingSource::Manual,
    );

    Mail::assertQueued(BookingConfirmedMail::class);
});

it('never calls stripe refund while a database transaction is open', function () {
    Mail::fake();
    $salon = aSalon(['tenant' => ['stripe_account_id' => 'acct_tx2', 'stripe_onboarding_complete' => true]]);
    $this->stripe->completeAccount('acct_tx2');
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $booking = paidBooking($salon, $startsAt, 'pi_far_tx');

    app(BookingService::class)->cancel($booking, 'customer', false);

    expect($this->stripe->refundLevels)->toBe([$this->baseline]);
});

it('keeps the cancellation and the refund recorded when notifying afterwards fails', function () {
    Mail::fake();
    $salon = aSalon(['tenant' => ['stripe_account_id' => 'acct_tx3', 'stripe_onboarding_complete' => true]]);
    $this->stripe->completeAccount('acct_tx3');
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $booking = paidBooking($salon, $startsAt, 'pi_fail_tx');

    $this->sms->throw = true;

    try {
        app(BookingService::class)->cancel($booking, 'customer', false);
    } catch (Throwable) {
        // The notification may fail; the money and the booking state must not.
    }

    $fresh = $booking->fresh();

    expect($fresh->status)->toBe(BookingStatus::Cancelled)
        ->and($fresh->deposit_status)->toBe(DepositStatus::Refunded)
        ->and($this->stripe->refunds)->toContain('pi_fail_tx');
});

it('does not lose a booking when the SMS provider is down', function () {
    Mail::fake();
    // Production runs a real queue; under the sync driver the job would execute
    // inline and defeat the isolation this test is about.
    config(['queue.default' => 'database']);
    $salon = aSalon();
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'phone' => '+447700900000']);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $this->sms->throw = true;

    app(BookingService::class)->create(
        $salon['tenant'], $salon['service'], $salon['staff'], $customer, $startsAt, BookingSource::Manual,
    );

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe(1)
        ->and($this->sms->levels)->toBeEmpty();
});

it('creates the payment intent outside the booking transaction', function () {
    Mail::fake();
    $salon = aConnectedSalon(1000, 'acct_tx4');
    $this->stripe->completeAccount('acct_tx4');
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'phone' => '+447700900000']);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    app(BookingService::class)->create(
        $salon['tenant'], $salon['service'], $salon['staff'], $customer, $startsAt, BookingSource::Online,
    );

    expect($this->stripe->intentLevels)->toBe([$this->baseline]);
});
