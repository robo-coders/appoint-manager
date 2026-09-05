<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Models\LoyaltyPackage;
use App\Models\Message;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Loyalty\Loyalty;
use App\Services\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Loyalty packages, v1.
 *
 * The three rules the feature is: a stamp per completed appointment, the next
 * one free when the card is full, and nothing at all when the tenant has not
 * switched it on. The last is the one worth having tests for — an opt-in feature
 * that leaks into a tenant that declined it is worse than a feature that does
 * not work.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-03 08:00:00', 'Europe/London'));
});

/**
 * A salon with loyalty on and one package.
 *
 * @return array{tenant: Tenant, staff: User, service: Service, package: LoyaltyPackage}
 */
function aLoyaltySalon(int $sessions = 5, array $overrides = []): array
{
    $salon = aSalon($overrides);
    $tenant = $salon['tenant'];

    $settings = $tenant->settings ?? [];
    $settings['loyalty']['enabled'] = true;
    $tenant->forceFill(['settings' => $settings])->save();

    $package = LoyaltyPackage::factory()->create([
        'tenant_id' => $tenant->id,
        'sessions_required' => $sessions,
    ]);

    return [...$salon, 'tenant' => $tenant->fresh(), 'package' => $package];
}

function aLoyaltyCustomer(Tenant $tenant, string $name = 'Alex Reed'): Customer
{
    $customer = new Customer;
    $customer->forceFill([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'email' => str()->slug($name).'@example.com',
        'phone' => '+447700900000',
    ])->save();

    return $customer;
}

/** Book one appointment through the service, as the salon would. */
function bookFor(array $salon, Customer $customer, string $when = '2026-03-10 09:00:00'): Booking
{
    return app(BookingService::class)->create(
        $salon['tenant'],
        $salon['service'],
        $salon['staff'],
        $customer,
        CarbonImmutable::parse($when, 'Europe/London')->utc(),
        BookingSource::Online,
    );
}

/*
|--------------------------------------------------------------------------
| Off by default
|--------------------------------------------------------------------------
*/

it('is off for a new tenant and touches nothing', function () {
    $salon = aSalon();
    $customer = aLoyaltyCustomer($salon['tenant']);

    expect(app(Loyalty::class)->enabled($salon['tenant']))->toBeFalse();

    $booking = bookFor($salon, $customer);

    expect(LoyaltyEnrolment::withoutGlobalScopes()->count())->toBe(0)
        ->and($booking->is_loyalty_reward)->toBeFalse()
        ->and($booking->price_at_booking->amount)->toBe(3500);
});

it('adds no stamp when the feature is off, even on a completed appointment', function () {
    $salon = aSalon();
    $customer = aLoyaltyCustomer($salon['tenant']);
    $booking = bookFor($salon, $customer);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));
    app(BookingService::class)->complete($booking);

    expect(LoyaltyEnrolment::withoutGlobalScopes()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Enrolment
|--------------------------------------------------------------------------
*/

it('enrols a customer automatically on their next booking', function () {
    $salon = aLoyaltySalon();
    $customer = aLoyaltyCustomer($salon['tenant']);

    bookFor($salon, $customer);

    $enrolment = LoyaltyEnrolment::withoutGlobalScopes()->sole();

    expect($enrolment->customer_id)->toBe($customer->id)
        ->and($enrolment->loyalty_package_id)->toBe($salon['package']->id)
        ->and($enrolment->stamps_used)->toBe(0)
        ->and($enrolment->cycles_completed)->toBe(0);
});

it('keeps a customer on one enrolment however many times they book', function () {
    $salon = aLoyaltySalon();
    $customer = aLoyaltyCustomer($salon['tenant']);

    bookFor($salon, $customer, '2026-03-10 09:00:00');
    bookFor($salon, $customer, '2026-03-10 11:00:00');
    bookFor($salon, $customer, '2026-03-17 09:00:00');

    expect(LoyaltyEnrolment::withoutGlobalScopes()->where('customer_id', $customer->id)->count())->toBe(1);
});

it('moves a customer onto the current package when theirs was switched off', function () {
    $salon = aLoyaltySalon();
    $customer = aLoyaltyCustomer($salon['tenant']);
    bookFor($salon, $customer);

    LoyaltyEnrolment::withoutGlobalScopes()->sole()->forceFill([
        'stamps_used' => 3,
        'cycles_completed' => 2,
    ])->save();

    // The old package goes away; a new one takes its place.
    $salon['package']->forceFill(['is_active' => false])->save();
    $replacement = LoyaltyPackage::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'sessions_required' => 8,
    ]);

    app(Loyalty::class)->enrol($salon['tenant'], $customer);
    $enrolment = LoyaltyEnrolment::withoutGlobalScopes()->sole();

    expect($enrolment->loyalty_package_id)->toBe($replacement->id)
        // The current cycle restarts against the new count; the completed ones
        // happened and are kept.
        ->and($enrolment->stamps_used)->toBe(0)
        ->and($enrolment->cycles_completed)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Stamps
|--------------------------------------------------------------------------
*/

it('adds one stamp per completed appointment and none for a booking', function () {
    $salon = aLoyaltySalon();
    $customer = aLoyaltyCustomer($salon['tenant']);

    $first = bookFor($salon, $customer, '2026-03-10 09:00:00');
    $second = bookFor($salon, $customer, '2026-03-17 09:00:00');

    expect(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-03-17 12:00:00', 'Europe/London'));
    app(BookingService::class)->complete($first);

    expect(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(1);

    app(BookingService::class)->complete($second);

    expect(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(2);
});

it('does not stamp twice for the same appointment', function () {
    $salon = aLoyaltySalon();
    $customer = aLoyaltyCustomer($salon['tenant']);
    $booking = bookFor($salon, $customer);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));
    app(BookingService::class)->complete($booking);
    app(BookingService::class)->complete($booking->fresh());

    expect(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(1);
});

it('does not stamp past the package count', function () {
    $salon = aLoyaltySalon(2);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 2,
    ]);

    $booking = bookFor($salon, $customer, '2026-03-24 09:00:00');
    // That booking is the reward, so completing it must not stamp either.
    $this->travelTo(CarbonImmutable::parse('2026-03-24 10:30:00', 'Europe/London'));
    app(BookingService::class)->complete($booking);

    expect($booking->fresh()->is_loyalty_reward)->toBeTrue()
        ->and(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The free one
|--------------------------------------------------------------------------
*/

it('makes the next booking free and skips the deposit once the card is full', function () {
    Mail::fake();
    $salon = aLoyaltySalon(2, [
        'tenant' => ['stripe_account_id' => 'acct_loyal', 'stripe_onboarding_complete' => true],
        'service' => ['deposit_amount' => 1000],
    ]);
    $customer = aLoyaltyCustomer($salon['tenant']);

    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 2,
    ]);

    $booking = bookFor($salon, $customer, '2026-03-10 09:00:00');

    expect($booking->is_loyalty_reward)->toBeTrue()
        ->and($booking->price_at_booking->amount)->toBe(0)
        ->and($booking->deposit_at_booking->amount)->toBe(0)
        ->and($booking->deposit_status)->toBe(DepositStatus::None)
        // Straight to confirmed: no card, so no pending-payment window.
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and(app(StripeGateway::class)->intents)->toBe([]);
});

it('resets the card when the free one is booked and counts the cycle', function () {
    $salon = aLoyaltySalon(3);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 3,
        'cycles_completed' => 1,
    ]);

    bookFor($salon, $customer, '2026-03-10 09:00:00');
    $enrolment = LoyaltyEnrolment::withoutGlobalScopes()->sole();

    expect($enrolment->stamps_used)->toBe(0)
        ->and($enrolment->cycles_completed)->toBe(2);
});

it('gives one free appointment per full card, not one per booking after it', function () {
    $salon = aLoyaltySalon(2);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 2,
    ]);

    $free = bookFor($salon, $customer, '2026-03-10 09:00:00');
    $next = bookFor($salon, $customer, '2026-03-10 11:00:00');

    expect($free->is_loyalty_reward)->toBeTrue()
        ->and($next->is_loyalty_reward)->toBeFalse()
        ->and($next->price_at_booking->amount)->toBe(3500);
});

it('charges normally while the card is not full', function () {
    $salon = aLoyaltySalon(5);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 4,
    ]);

    $booking = bookFor($salon, $customer);

    expect($booking->is_loyalty_reward)->toBeFalse()
        ->and($booking->price_at_booking->amount)->toBe(3500);
});

/*
|--------------------------------------------------------------------------
| What the customer is told
|--------------------------------------------------------------------------
*/

it('puts the stamp count on the booking confirmation text', function () {
    Mail::fake();
    $salon = aLoyaltySalon(5);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 2,
    ]);

    bookFor($salon, $customer);

    $sms = Message::withoutGlobalScopes()
        ->where('channel', MessageChannel::Sms)
        ->where('type', MessageType::BookingConfirmed)
        ->sole();

    // The count *after* this appointment, because the alternative asks the
    // customer to do the arithmetic the message exists to save them.
    expect($sms->body)->toContain('3 of 5 stamps')
        ->toContain('2 more until your free session');
});

it('says the free one is free on its confirmation', function () {
    Mail::fake();
    $salon = aLoyaltySalon(3);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 3,
    ]);

    bookFor($salon, $customer);

    /*
     * Filtered to the confirmation. `Notifier::bookingConfirmed` also schedules
     * the reminder, and the suite runs on `QUEUE_CONNECTION=sync`, which ignores
     * the delay and sends it inside the same request — so there are two SMS rows
     * and `sole()` on the channel alone finds both.
     */
    $sms = Message::withoutGlobalScopes()
        ->where('channel', MessageChannel::Sms)
        ->where('type', MessageType::BookingConfirmed)
        ->sole();

    expect($sms->body)->toContain('This one is free');
});

it('says the next one is free on the confirmation that fills the card', function () {
    Mail::fake();
    $salon = aLoyaltySalon(3);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 2,
    ]);

    bookFor($salon, $customer);

    /*
     * Asserted in two pieces on purpose. `SmsSegments::sanitise` folds the em
     * dash to a hyphen because GSM-7 has no em dash and one non-GSM character
     * turns the whole message into UCS-2 — which doubles the segments the salon
     * is billed for. So the body is not byte-identical to the composed string,
     * and matching the whole line would be asserting that the sanitiser is not
     * doing its job.
     */
    expect(Message::withoutGlobalScopes()
        ->where('channel', MessageChannel::Sms)
        ->where('type', MessageType::BookingConfirmed)
        ->sole()->body)
        ->toContain('3 of 3 stamps')
        ->toContain('the next one is free');
});

it('leaves the confirmation text alone for a tenant with the feature off', function () {
    Mail::fake();
    $salon = aSalon();
    $customer = aLoyaltyCustomer($salon['tenant']);

    bookFor($salon, $customer);

    expect(Message::withoutGlobalScopes()
        ->where('channel', MessageChannel::Sms)
        ->where('type', MessageType::BookingConfirmed)
        ->sole()->body)
        ->not->toContain('stamps');
});

/*
|--------------------------------------------------------------------------
| The owner's view
|--------------------------------------------------------------------------
*/

it('shows the card, the count and the free sessions on the customer screen', function () {
    $salon = aLoyaltySalon(3);
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 3,
        'cycles_completed' => 1,
    ]);

    $free = bookFor($salon, $customer, '2026-03-10 09:00:00');

    $this->actingAs($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Show')
            ->where('loyalty.sessions_required', 3)
            ->where('loyalty.stamps_used', 0)
            ->where('loyalty.cycles_completed', 2)
            ->where('loyalty.earning', true)
            ->where('loyalty.reward_due', false)
            ->has('loyalty.free_sessions', 1)
            ->where('loyalty.free_sessions.0.id', $free->id));
});

it('sends no loyalty panel to the customer screen when the feature is off', function () {
    $salon = aSalon();
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $customer = aLoyaltyCustomer($salon['tenant']);

    $this->actingAs($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('loyalty', null));
});

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
*/

it('shows the setting off by default with no package', function () {
    $salon = aSalon();
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);

    $this->actingAs($owner)
        ->get(route('settings.loyalty.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Loyalty')
            ->where('loyalty.enabled', false)
            ->where('loyalty.name', null)
            ->where('loyalty.enrolled', 0));
});

it('switches the feature on and creates the one package', function () {
    $salon = aSalon();
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), [
            'enabled' => true,
            'name' => 'Groom card',
            'sessions_required' => 6,
            'reward' => 'A free full groom',
        ])
        ->assertRedirect(route('settings.loyalty.edit'))
        ->assertSessionHasNoErrors();

    $package = LoyaltyPackage::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect(app(Loyalty::class)->enabled($salon['tenant']->fresh()))->toBeTrue()
        ->and($package->name)->toBe('Groom card')
        ->and($package->sessions_required)->toBe(6);
});

it('edits the scheme in place rather than adding a second package', function () {
    $salon = aLoyaltySalon(5);
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), [
            'enabled' => true,
            'name' => 'Loyalty card',
            'sessions_required' => 6,
            'reward' => 'The next session is free',
        ])
        ->assertSessionHasNoErrors();

    expect(LoyaltyPackage::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe(1)
        ->and($salon['package']->fresh()->sessions_required)->toBe(6);
});

it('switches the feature off without deleting anybody progress', function () {
    $salon = aLoyaltySalon(5);
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 3,
    ]);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), ['enabled' => false])
        ->assertSessionHasNoErrors();

    expect(app(Loyalty::class)->enabled($salon['tenant']->fresh()))->toBeFalse()
        ->and(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(3)
        // And nothing is free while it is off, however full the card was.
        ->and(app(Loyalty::class)->rewardDue($salon['tenant']->fresh(), $customer))->toBeFalse();
});

it('asks for the package details only when the feature is being switched on', function () {
    $salon = aSalon();
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), ['enabled' => true])
        ->assertSessionHasErrors(['name', 'sessions_required', 'reward']);
});

it('refuses a package of one session', function () {
    $salon = aSalon();
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), [
            'enabled' => true,
            'name' => 'Every one free',
            'sessions_required' => 1,
            'reward' => 'Free',
        ])
        ->assertSessionHasErrors('sessions_required');
});

it('keeps one salon loyalty out of another', function () {
    $salon = aLoyaltySalon(5);
    $other = aLoyaltySalon(5);
    $customer = aLoyaltyCustomer($salon['tenant']);

    bookFor($salon, $customer);

    $enrolment = LoyaltyEnrolment::withoutGlobalScopes()->sole();

    expect($enrolment->tenant_id)->toBe($salon['tenant']->id)
        ->and($enrolment->loyalty_package_id)->toBe($salon['package']->id)
        ->and($enrolment->loyalty_package_id)->not->toBe($other['package']->id);
});

/*
|--------------------------------------------------------------------------
| Marking an appointment as done
|--------------------------------------------------------------------------
*/

it('refuses to complete an appointment that has not happened yet', function () {
    $salon = aLoyaltySalon();
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $customer = aLoyaltyCustomer($salon['tenant']);
    $booking = bookFor($salon, $customer, '2026-03-17 09:00:00');

    $this->actingAs($owner)
        ->from(route('bookings.show', $booking))
        ->post(route('bookings.complete', $booking))
        ->assertSessionHasErrors('status');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(0);
});

it('completes a past appointment from the booking screen and stamps it', function () {
    $salon = aLoyaltySalon();
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $customer = aLoyaltyCustomer($salon['tenant']);
    $booking = bookFor($salon, $customer, '2026-03-10 09:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));

    $this->actingAs($owner)
        ->post(route('bookings.complete', $booking))
        ->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed)
        ->and(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(1);
});

it('refuses to complete a cancelled appointment', function () {
    $salon = aLoyaltySalon();
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $customer = aLoyaltyCustomer($salon['tenant']);
    $booking = bookFor($salon, $customer, '2026-03-10 09:00:00');
    $booking->forceFill(['status' => BookingStatus::Cancelled])->save();

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));

    $this->actingAs($owner)
        ->from(route('bookings.show', $booking))
        ->post(route('bookings.complete', $booking))
        ->assertSessionHasErrors('status');
});

/*
|--------------------------------------------------------------------------
| Cancelling the free one
|--------------------------------------------------------------------------
|
| The bug: `spendReward()` clears the card at booking, and nothing put it back.
| A customer who earned a free session, booked it, and then had it called off
| had paid five stamps for nothing.
*/

it('gives the stamps back when the free one is cancelled', function () {
    $salon = aLoyaltySalon(3);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 3,
        'cycles_completed' => 1,
    ]);

    $free = bookFor($salon, $customer, '2026-03-10 09:00:00');
    expect($free->is_loyalty_reward)->toBeTrue();

    app(BookingService::class)->cancel($free, 'admin');

    $enrolment = LoyaltyEnrolment::withoutGlobalScopes()->sole();

    expect($enrolment->stamps_used)->toBe(3)
        ->and($enrolment->cycles_completed)->toBe(1)
        // The point of all of it: the next one is free again.
        ->and(app(Loyalty::class)->rewardDue($salon['tenant'], $customer))->toBeTrue();
});

it('makes the customer\'s next booking free again after the reward is cancelled', function () {
    $salon = aLoyaltySalon(2);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 2,
    ]);

    $free = bookFor($salon, $customer, '2026-03-10 09:00:00');
    app(BookingService::class)->cancel($free, 'admin');

    $replacement = bookFor($salon, $customer, '2026-03-17 09:00:00');

    expect($replacement->is_loyalty_reward)->toBeTrue()
        ->and($replacement->price_at_booking->amount)->toBe(0);
});

it('leaves the stamps alone when an ordinary booking is cancelled', function () {
    $salon = aLoyaltySalon(5);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 2,
        'cycles_completed' => 1,
    ]);

    $booking = bookFor($salon, $customer, '2026-03-10 09:00:00');
    expect($booking->is_loyalty_reward)->toBeFalse();

    app(BookingService::class)->cancel($booking, 'admin');

    $enrolment = LoyaltyEnrolment::withoutGlobalScopes()->sole();

    expect($enrolment->stamps_used)->toBe(2)
        ->and($enrolment->cycles_completed)->toBe(1);
});

it('gives the stamps back once, however many times the reward is cancelled', function () {
    $salon = aLoyaltySalon(3);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 3,
        'cycles_completed' => 2,
    ]);

    $free = bookFor($salon, $customer, '2026-03-10 09:00:00');
    app(BookingService::class)->cancel($free, 'admin');
    app(BookingService::class)->cancel($free->fresh(), 'admin');

    $enrolment = LoyaltyEnrolment::withoutGlobalScopes()->sole();

    expect($enrolment->stamps_used)->toBe(3)
        ->and($enrolment->cycles_completed)->toBe(2);
});

it('gives the stamps back from the cancel button on the booking screen', function () {
    $salon = aLoyaltySalon(3);
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 3,
    ]);

    $free = bookFor($salon, $customer, '2026-03-10 09:00:00');

    $this->actingAs($owner)
        ->delete(route('bookings.destroy', $free), ['offer_waitlist' => false])
        ->assertRedirect(route('bookings.index'));

    expect(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(3);
});

it('keeps the reward spent when the free one is a no-show rather than cancelled', function () {
    $salon = aLoyaltySalon(3);
    $customer = aLoyaltyCustomer($salon['tenant']);
    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $salon['package']->id,
        'stamps_used' => 3,
    ]);

    $free = bookFor($salon, $customer, '2026-03-10 09:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));
    app(BookingService::class)->markNoShow($free);

    // The slot was held and nobody else could have it. The stamps stay spent.
    expect(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(0)
        ->and(app(Loyalty::class)->rewardDue($salon['tenant'], $customer))->toBeFalse();
});

it('adds no stamp for a no-show on an ordinary booking', function () {
    $salon = aLoyaltySalon(5);
    $customer = aLoyaltyCustomer($salon['tenant']);
    $booking = bookFor($salon, $customer, '2026-03-10 09:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));
    app(BookingService::class)->markNoShow($booking);

    expect(LoyaltyEnrolment::withoutGlobalScopes()->sole()->stamps_used)->toBe(0);
});
