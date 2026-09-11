<?php

use App\Enums\BookingSource;
use App\Enums\LoyaltyCardStatus;
use App\Enums\LoyaltyStampMethod;
use App\Exceptions\LoyaltyCardFullException;
use App\Exceptions\ManualStampRequiresNoteException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Models\LoyaltyPackage;
use App\Models\LoyaltyStamp;
use App\Models\Service;
use App\Services\Booking\BookingService;
use App\Services\Loyalty\Loyalty;
use App\Services\Loyalty\LoyaltyStampService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-03 08:00:00', 'Europe/London'));
});

function aStampingSalon(array $package = [], int $sessions = 5): array
{
    $salon = aSalon();
    $tenant = $salon['tenant'];

    $settings = $tenant->settings ?? [];
    $settings['loyalty']['enabled'] = true;
    $tenant->forceFill(['settings' => $settings])->save();

    $scheme = LoyaltyPackage::factory()->create([
        'tenant_id' => $tenant->id,
        'sessions_required' => $sessions,
    ]);

    $scheme->forceFill($package)->save();

    $customer = new Customer;
    $customer->forceFill([
        'tenant_id' => $tenant->id,
        'name' => 'Alex Reed',
        'email' => 'alex-'.$tenant->id.'@example.com',
        'phone' => '+447700900000',
    ])->save();

    app(Loyalty::class)->enrol($tenant->fresh(), $customer);

    return [...$salon, 'tenant' => $tenant->fresh(), 'package' => $scheme->fresh(), 'customer' => $customer];
}

function bookVisit(array $salon, Customer $customer, string $when = '2026-03-10 09:00:00'): Booking
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

function stampService(): LoyaltyStampService
{
    return app(LoyaltyStampService::class);
}

function cardFor(Customer $customer): LoyaltyEnrolment
{
    return LoyaltyEnrolment::withoutGlobalScopes()->where('customer_id', $customer->id)->sole();
}

it('writes one stamp carrying the visit date when an appointment is completed', function () {
    $salon = aStampingSalon();
    $booking = bookVisit($salon, $salon['customer']);

    $stamp = stampService()->stampAutomatically($booking);

    expect($stamp)->not->toBeNull()
        ->and($stamp->method)->toBe(LoyaltyStampMethod::Automatic)
        ->and($stamp->stamped_by)->toBeNull()
        ->and($stamp->booking_id)->toBe($booking->id)
        ->and($stamp->visit_date->toDateString())->toBe('2026-03-10')
        ->and(cardFor($salon['customer'])->stamps_used)->toBe(1);
});

it('stamps once however many times the same completion is processed', function () {
    $salon = aStampingSalon();
    $booking = bookVisit($salon, $salon['customer']);

    $first = stampService()->stampAutomatically($booking);
    $second = stampService()->stampAutomatically($booking);
    $third = stampService()->stampAutomatically($booking);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($third)->toBeNull()
        ->and(LoyaltyStamp::withoutGlobalScopes()->count())->toBe(1)
        ->and(cardFor($salon['customer'])->stamps_used)->toBe(1);
});

it('refuses a second stamp row for one appointment at the database', function () {
    $salon = aStampingSalon();
    $booking = bookVisit($salon, $salon['customer']);
    $card = cardFor($salon['customer']);

    stampService()->stampAutomatically($booking);

    $duplicate = fn () => LoyaltyStamp::withoutGlobalScopes()->create([
        'tenant_id' => $salon['tenant']->id,
        'loyalty_enrolment_id' => $card->id,
        'booking_id' => $booking->id,
        'method' => LoyaltyStampMethod::Automatic,
        'visit_date' => '2026-03-10',
    ]);

    expect($duplicate)->toThrow(QueryException::class)
        ->and(LoyaltyStamp::withoutGlobalScopes()->count())->toBe(1);
});

it('never fills a card past the count the scheme asks for', function () {
    $salon = aStampingSalon(sessions: 3);
    $customer = $salon['customer'];

    $bookings = collect(['2026-03-10 09:00:00', '2026-03-17 09:00:00', '2026-03-24 09:00:00', '2026-03-31 09:00:00'])
        ->map(fn (string $when) => bookVisit($salon, $customer, $when));

    $bookings->each(fn ($booking) => stampService()->stampAutomatically($booking));

    $card = cardFor($customer);

    expect($card->stamps_used)->toBe(3)
        ->and(LoyaltyStamp::withoutGlobalScopes()->count())->toBe(3)
        ->and($card->status)->toBe(LoyaltyCardStatus::StampedOut);
});

it('marks the card stamped out and dates it when the last stamp lands', function () {
    $salon = aStampingSalon(sessions: 2);
    $customer = $salon['customer'];

    stampService()->stampAutomatically(bookVisit($salon, $customer, '2026-03-10 09:00:00'));

    expect(cardFor($customer)->status)->toBe(LoyaltyCardStatus::Active)
        ->and(cardFor($customer)->completed_at)->toBeNull();

    stampService()->stampAutomatically(bookVisit($salon, $customer, '2026-03-17 09:00:00'));

    $card = cardFor($customer);

    expect($card->status)->toBe(LoyaltyCardStatus::StampedOut)
        ->and($card->completed_at)->not->toBeNull()
        ->and($card->rewardDue())->toBeTrue();
});

it('makes the next appointment free once the card is stamped out', function () {
    $salon = aStampingSalon(sessions: 2);
    $customer = $salon['customer'];

    stampService()->stampAutomatically(bookVisit($salon, $customer, '2026-03-10 09:00:00'));
    stampService()->stampAutomatically(bookVisit($salon, $customer, '2026-03-17 09:00:00'));

    $reward = bookVisit($salon, $customer, '2026-03-24 09:00:00');

    expect($reward->is_loyalty_reward)->toBeTrue()
        ->and($reward->price_at_booking->amount)->toBe(0)
        ->and(cardFor($customer)->status)->toBe(LoyaltyCardStatus::Redeemed)
        ->and(cardFor($customer)->redeemed_at)->not->toBeNull();
});

it('leaves the reward for the operator when applying it automatically is off', function () {
    $salon = aStampingSalon(['auto_apply_reward' => false], sessions: 2);
    $customer = $salon['customer'];

    stampService()->stampAutomatically(bookVisit($salon, $customer, '2026-03-10 09:00:00'));
    stampService()->stampAutomatically(bookVisit($salon, $customer, '2026-03-17 09:00:00'));

    $next = bookVisit($salon, $customer, '2026-03-24 09:00:00');

    expect(cardFor($customer)->status)->toBe(LoyaltyCardStatus::StampedOut)
        ->and($next->is_loyalty_reward)->toBeFalse()
        ->and($next->price_at_booking->amount)->toBe(3500);
});

it('opens a card on the first qualifying visit when nobody has one yet', function () {
    $salon = aStampingSalon();
    $customer = $salon['customer'];
    $booking = bookVisit($salon, $customer);

    LoyaltyEnrolment::withoutGlobalScopes()->where('customer_id', $customer->id)->delete();

    $stamp = stampService()->stampAutomatically($booking);

    expect($stamp)->not->toBeNull()
        ->and(cardFor($customer)->stamps_used)->toBe(1)
        ->and(cardFor($customer)->loyalty_package_id)->toBe($salon['package']->id);
});

it('opens no card and writes no stamp when enrolling everybody is off', function () {
    $salon = aStampingSalon(['auto_enrol' => false]);
    $customer = $salon['customer'];
    $booking = bookVisit($salon, $customer);

    LoyaltyEnrolment::withoutGlobalScopes()->where('customer_id', $customer->id)->delete();

    expect(stampService()->stampAutomatically($booking))->toBeNull()
        ->and(LoyaltyEnrolment::withoutGlobalScopes()->count())->toBe(0)
        ->and(LoyaltyStamp::withoutGlobalScopes()->count())->toBe(0);
});

it('writes no stamp when the scheme is switched off', function () {
    $salon = aStampingSalon();
    $booking = bookVisit($salon, $salon['customer']);

    $settings = $salon['tenant']->settings;
    $settings['loyalty']['enabled'] = false;
    $salon['tenant']->forceFill(['settings' => $settings])->save();

    expect(stampService()->stampAutomatically($booking->fresh()))->toBeNull()
        ->and(LoyaltyStamp::withoutGlobalScopes()->count())->toBe(0);
});

it('writes no stamp when stamping automatically is off', function () {
    $salon = aStampingSalon(['auto_stamp' => false]);
    $booking = bookVisit($salon, $salon['customer']);

    expect(stampService()->stampAutomatically($booking))->toBeNull()
        ->and(LoyaltyStamp::withoutGlobalScopes()->count())->toBe(0);
});

it('writes no stamp for a service the card does not cover', function () {
    $salon = aStampingSalon();

    $other = Service::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'is_active' => true,
        'price' => 3500,
        'deposit_amount' => 0,
    ]);

    $salon['package']->forceFill(['eligible_service_id' => $other->id])->save();

    $booking = bookVisit($salon, $salon['customer']);

    expect(stampService()->stampAutomatically($booking))->toBeNull()
        ->and(LoyaltyStamp::withoutGlobalScopes()->count())->toBe(0);
});

it('stamps a service the card is scoped to', function () {
    $salon = aStampingSalon();
    $salon['package']->forceFill(['eligible_service_id' => $salon['service']->id])->save();

    $booking = bookVisit($salon, $salon['customer']);

    expect(stampService()->stampAutomatically($booking))->not->toBeNull()
        ->and(cardFor($salon['customer'])->stamps_used)->toBe(1);
});

it('adds no stamp for the free appointment itself', function () {
    $salon = aStampingSalon(sessions: 2);
    $customer = $salon['customer'];

    stampService()->stampAutomatically(bookVisit($salon, $customer, '2026-03-10 09:00:00'));
    stampService()->stampAutomatically(bookVisit($salon, $customer, '2026-03-17 09:00:00'));

    $reward = bookVisit($salon, $customer, '2026-03-24 09:00:00');

    expect($reward->is_loyalty_reward)->toBeTrue()
        ->and(stampService()->stampAutomatically($reward))->toBeNull();
});

it('refuses a stamp with neither an appointment nor a note', function () {
    $salon = aStampingSalon();
    $card = cardFor($salon['customer']);

    expect(fn () => stampService()->stampManually($card, null, $salon['staff'], null))
        ->toThrow(ManualStampRequiresNoteException::class)
        ->and(LoyaltyStamp::withoutGlobalScopes()->count())->toBe(0);

    expect(fn () => stampService()->stampManually($card, null, $salon['staff'], '   '))
        ->toThrow(ManualStampRequiresNoteException::class);
});

it('accepts a goodwill stamp that explains itself', function () {
    $salon = aStampingSalon();
    $card = cardFor($salon['customer']);

    $stamp = stampService()->stampManually($card, null, $salon['staff'], 'Walk-in, paid cash');

    expect($stamp->method)->toBe(LoyaltyStampMethod::Manual)
        ->and($stamp->booking_id)->toBeNull()
        ->and($stamp->stamped_by)->toBe($salon['staff']->id)
        ->and($stamp->note)->toBe('Walk-in, paid cash')
        ->and($stamp->visit_date->toDateString())->toBe('2026-03-03')
        ->and(cardFor($salon['customer'])->stamps_used)->toBe(1);
});

it('accepts a stamp with an appointment behind it and no note', function () {
    $salon = aStampingSalon();
    $card = cardFor($salon['customer']);
    $booking = bookVisit($salon, $salon['customer']);

    $stamp = stampService()->stampManually($card, $booking, $salon['staff'], null);

    expect($stamp->note)->toBeNull()
        ->and($stamp->booking_id)->toBe($booking->id)
        ->and($stamp->visit_date->toDateString())->toBe('2026-03-10');
});

it('records a stamp as by-hand even when an appointment is attached', function () {
    $salon = aStampingSalon();
    $card = cardFor($salon['customer']);
    $booking = bookVisit($salon, $salon['customer']);

    $stamp = stampService()->stampManually($card, $booking, $salon['staff'], null);

    expect($stamp->method)->toBe(LoyaltyStampMethod::Manual)
        ->and($stamp->stamped_by)->toBe($salon['staff']->id);
});

it('refuses a by-hand stamp for an appointment already stamped', function () {
    $salon = aStampingSalon();
    $card = cardFor($salon['customer']);
    $booking = bookVisit($salon, $salon['customer']);

    stampService()->stampAutomatically($booking);

    expect(fn () => stampService()->stampManually($card, $booking, $salon['staff'], null))
        ->toThrow(LoyaltyCardFullException::class)
        ->and(LoyaltyStamp::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses a by-hand stamp on a card that is already full', function () {
    $salon = aStampingSalon(sessions: 2);
    $customer = $salon['customer'];

    stampService()->stampAutomatically(bookVisit($salon, $customer, '2026-03-10 09:00:00'));
    stampService()->stampAutomatically(bookVisit($salon, $customer, '2026-03-17 09:00:00'));

    expect(fn () => stampService()->stampManually(cardFor($customer), null, $salon['staff'], 'One more'))
        ->toThrow(LoyaltyCardFullException::class);
});

it('stamps from the booking-completion hook rather than from any one route', function () {
    $salon = aStampingSalon();
    $booking = bookVisit($salon, $salon['customer']);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));
    app(BookingService::class)->complete($booking);

    $stamp = LoyaltyStamp::withoutGlobalScopes()->sole();

    expect($stamp->booking_id)->toBe($booking->id)
        ->and($stamp->method)->toBe(LoyaltyStampMethod::Automatic)
        ->and($stamp->stamped_by)->toBeNull();
});

it('keeps one salon stamps out of another', function () {
    $salon = aStampingSalon();
    $other = aStampingSalon();

    stampService()->stampAutomatically(bookVisit($salon, $salon['customer']));
    stampService()->stampAutomatically(bookVisit($other, $other['customer']));

    $stamps = LoyaltyStamp::withoutGlobalScopes()->get();

    expect($stamps)->toHaveCount(2)
        ->and($stamps->where('tenant_id', $salon['tenant']->id))->toHaveCount(1)
        ->and($stamps->where('tenant_id', $other['tenant']->id))->toHaveCount(1);
});
