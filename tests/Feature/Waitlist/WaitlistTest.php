<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\MessageType;
use App\Enums\PreferredTime;
use App\Enums\SlotOfferStatus;
use App\Enums\Weekday;
use App\Exceptions\OfferUnavailableException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\SlotOffer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Booking\BookingService;
use App\Services\Waitlist\WaitlistOfferer;
use Carbon\CarbonImmutable;
use Tests\Support\Concurrent;

function waitlistSalon(): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'is_active' => true,
        'deposit_amount' => 0,
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

function waiting(Tenant $tenant, Service $service, array $overrides = []): WaitlistEntry
{
    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'phone' => $overrides['phone'] ?? '+447700900001',
        'email' => fake()->unique()->safeEmail(),
    ]);

    return WaitlistEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'preferred_days' => $overrides['preferred_days'] ?? [2],
        'preferred_times' => $overrides['preferred_times'] ?? PreferredTime::Any,
        'is_active' => true,
        'created_at' => $overrides['created_at'] ?? now(),
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

afterEach(function () {
    Concurrent::afterEach();
});

it('lets exactly one of two simultaneous claims win and returns 409 to the other', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = waitlistSalon();
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $a = waiting($tenant, $service, ['phone' => '+447700900001']);
    $b = waiting($tenant, $service, ['phone' => '+447700900002']);

    $first = SlotOffer::factory()->create([
        'tenant_id' => $tenant->id,
        'waitlist_entry_id' => $a->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $starts,
        'ends_at' => $starts->addHour(),
        'status' => SlotOfferStatus::Sent,
        'expires_at' => now()->addMinutes(30),
    ]);
    $second = SlotOffer::factory()->create([
        'tenant_id' => $tenant->id,
        'waitlist_entry_id' => $b->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $starts,
        'ends_at' => $starts->addHour(),
        'status' => SlotOfferStatus::Sent,
        'expires_at' => now()->addMinutes(30),
    ]);

    $results = Concurrent::withoutWrappingTransaction(fn () => Concurrent::run([
        ['type' => 'http', 'method' => 'POST', 'uri' => route('offer.claim', $first->token, absolute: false), 'payload' => []],
        ['type' => 'http', 'method' => 'POST', 'uri' => route('offer.claim', $second->token, absolute: false), 'payload' => []],
    ]));

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([200, 409], 'workers: '.json_encode($results))
        ->and(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);

    $states = [
        $first->fresh()->status,
        $second->fresh()->status,
    ];

    expect($states)->toContain(SlotOfferStatus::Claimed)
        ->and($states)->toContain(SlotOfferStatus::Superseded);
});

it('expires sibling offers when one is claimed', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = waitlistSalon();
    $starts = CarbonImmutable::parse('2026-03-10 10:00:00', 'Europe/London')->utc();
    $winner = waiting($tenant, $service);
    $other = waiting($tenant, $service);

    $offer = SlotOffer::factory()->create([
        'tenant_id' => $tenant->id,
        'waitlist_entry_id' => $winner->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $starts,
        'ends_at' => $starts->addHour(),
        'status' => SlotOfferStatus::Sent,
        'expires_at' => now()->addMinutes(30),
    ]);
    $sibling = SlotOffer::factory()->create([
        'tenant_id' => $tenant->id,
        'waitlist_entry_id' => $other->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $starts,
        'ends_at' => $starts->addHour(),
        'status' => SlotOfferStatus::Sent,
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->postJson(route('offer.claim', $offer->token))->assertOk();

    expect($sibling->fresh()->status)->toBe(SlotOfferStatus::Superseded);
});

it('rejects an expired offer', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = waitlistSalon();
    $starts = CarbonImmutable::parse('2026-03-10 11:00:00', 'Europe/London')->utc();
    $entry = waiting($tenant, $service);

    $offer = SlotOffer::factory()->create([
        'tenant_id' => $tenant->id,
        'waitlist_entry_id' => $entry->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $starts,
        'ends_at' => $starts->addHour(),
        'status' => SlotOfferStatus::Sent,
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson(route('offer.claim', $offer->token))->assertStatus(410);
});

it('excludes waitlist entries whose day or time preferences do not match', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = waitlistSalon();
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    waiting($tenant, $service, ['preferred_days' => [3]]);
    waiting($tenant, $service, ['preferred_days' => [2], 'preferred_times' => PreferredTime::Afternoon]);
    $match = waiting($tenant, $service, ['preferred_days' => [2], 'preferred_times' => PreferredTime::Morning]);

    $ranked = app(WaitlistOfferer::class)->rankedMatches($tenant, $service, $starts);

    expect($ranked->pluck('id')->all())->toBe([$match->id]);
});

it('ranks waitlist matches deterministically', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = waitlistSalon();
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $anyNew = waiting($tenant, $service, [
        'preferred_times' => PreferredTime::Any,
        'created_at' => now()->subDay(),
    ]);
    $morningOld = waiting($tenant, $service, [
        'preferred_times' => PreferredTime::Morning,
        'created_at' => now()->subHours(2),
    ]);
    $morningOlder = waiting($tenant, $service, [
        'preferred_times' => PreferredTime::Morning,
        'created_at' => now()->subDays(3),
    ]);

    $ranked = app(WaitlistOfferer::class)->rankedMatches($tenant, $service, $starts);

    expect($ranked->pluck('id')->all())->toBe([$morningOlder->id, $morningOld->id, $anyNew->id]);
});

it('triggers exactly one waitlist blast when a booking is cancelled', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = waitlistSalon();
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    waiting($tenant, $service);
    waiting($tenant, $service);
    waiting($tenant, $service);

    $booking = app(BookingService::class)->create(
        $tenant,
        $service,
        $staff,
        Customer::factory()->create(['tenant_id' => $tenant->id]),
        $starts,
        BookingSource::Manual,
    );

    app(BookingService::class)->cancel($booking);

    expect(SlotOffer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| A no-show frees the hour too
|--------------------------------------------------------------------------
|
| A missed appointment leaves the same hole in the day as a cancellation, and
| for a while it was the only way of leaving one that told nobody: the status
| changed, the diary showed a gap, and the three people waiting for that exact
| Tuesday morning were never asked. `markNoShow()` now hands the window to the
| same `WaitlistOfferer::offerForBooking()` every other freed-slot path uses, so
| these assertions are deliberately the mirror of the cancellation one above —
| same fixture, same count, same wording.
*/

/** The salon, three people waiting, and one appointment that gets missed. */
function aMissedAppointment(array $salon, CarbonImmutable $starts): Booking
{
    return app(BookingService::class)->create(
        $salon['tenant'],
        $salon['service'],
        $salon['staff'],
        Customer::factory()->create(['tenant_id' => $salon['tenant']->id]),
        $starts,
        BookingSource::Manual,
    );
}

it('triggers the same waitlist blast when a booking is marked a no show', function () {
    $salon = waitlistSalon();
    ['tenant' => $tenant, 'service' => $service] = $salon;
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    waiting($tenant, $service, ['phone' => '+447700900001']);
    waiting($tenant, $service, ['phone' => '+447700900002']);
    waiting($tenant, $service, ['phone' => '+447700900003']);

    $booking = aMissedAppointment($salon, $starts);

    // The customer is a quarter of an hour late and the owner gives up on them.
    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:15:00', 'Europe/London'));
    app(BookingService::class)->markNoShow($booking);

    $offers = SlotOffer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow)
        ->and($offers)->toHaveCount(3)
        ->and($offers->pluck('status')->unique()->all())->toBe([SlotOfferStatus::Sent])
        ->and($offers->pluck('starts_at')->map(fn ($at) => $at->utc()->toIso8601String())->unique()->all())
        ->toBe([$starts->toIso8601String()]);
});

it('sends the freed-slot wording and never says the slot was missed', function () {
    $salon = waitlistSalon();
    ['tenant' => $tenant, 'service' => $service] = $salon;
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    waiting($tenant, $service);

    $booking = aMissedAppointment($salon, $starts);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:15:00', 'Europe/London'));
    app(BookingService::class)->markNoShow($booking);

    $sms = Message::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('type', MessageType::WaitlistOffer->value)
        ->sole();

    // The exact template `Notifier::waitlistOffer` composes for every other
    // freed slot — nothing about this one is written here.
    expect($sms->body)->toContain('a slot is free. Claim:')
        ->and($sms->body)->not->toContain('no show')
        ->and($sms->body)->not->toContain('no-show')
        ->and($sms->body)->not->toContain('miss');
});

it('does nothing when nobody is waiting for that slot', function () {
    $salon = waitlistSalon();
    ['tenant' => $tenant, 'service' => $service] = $salon;
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    // Waiting, but for Thursdays. The blast runs and matches nobody.
    waiting($tenant, $service, ['preferred_days' => [4]]);

    $booking = aMissedAppointment($salon, $starts);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:15:00', 'Europe/London'));
    app(BookingService::class)->markNoShow($booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow)
        ->and(SlotOffer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('does not blast a second time when the button is pressed twice', function () {
    $salon = waitlistSalon();
    ['tenant' => $tenant, 'service' => $service] = $salon;
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    waiting($tenant, $service, ['phone' => '+447700900001']);
    waiting($tenant, $service, ['phone' => '+447700900002']);

    $booking = aMissedAppointment($salon, $starts);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:15:00', 'Europe/London'));
    app(BookingService::class)->markNoShow($booking);
    app(BookingService::class)->markNoShow($booking->fresh());

    expect(SlotOffer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(Message::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('type', MessageType::WaitlistOffer->value)
            ->count())->toBe(2);
});

it('does not blast again for an entry that already holds a live offer', function () {
    $salon = waitlistSalon();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = $salon;
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $entry = waiting($tenant, $service);

    // The salon already offered this exact hour by hand before giving up on the
    // customer who did not turn up for it.
    SlotOffer::factory()->create([
        'tenant_id' => $tenant->id,
        'waitlist_entry_id' => $entry->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $starts,
        'ends_at' => $starts->addHour(),
        'status' => SlotOfferStatus::Sent,
        'expires_at' => CarbonImmutable::parse('2026-03-10 10:00:00', 'Europe/London'),
    ]);

    $booking = aMissedAppointment($salon, $starts);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:15:00', 'Europe/London'));
    app(BookingService::class)->markNoShow($booking);

    expect(SlotOffer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The offer can actually be taken
|--------------------------------------------------------------------------
|
| Creating the offer was never the hard part. `markNoShow()` handed the hour to
| the waitlist, three people got a text, and every one of them hit "Sorry, that
| slot was just taken" — because the missed booking still occupied the slot it
| was advertising. `BookingStatus::NoShow` joining `BookingStatus::vacating()`
| is what makes the claim go through.
|
| Read the clock in this test carefully, because it is doing real work. The
| no-show is marked at the appointment's own start time, on a salon that asks
| for no notice, and that is the *only* arrangement in which the claim can
| currently succeed: `markNoShow()` refuses a booking that has not started, and
| the engine refuses a start that is already behind `now + min_notice_hours`.
| Those two rules leave exactly one instant. See the note on the following test.
*/
it('lets a waitlisted customer actually claim the hour a no show freed', function () {
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'settings' => ['booking' => ['min_notice_hours' => 0]],
    ]);
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'is_active' => true,
        'deposit_amount' => 0,
    ]);
    $service->staff()->attach($staff->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $entry = waiting($tenant, $service);

    $booking = app(BookingService::class)->create(
        $tenant, $service, $staff,
        Customer::factory()->create(['tenant_id' => $tenant->id]),
        $starts, BookingSource::Manual,
    );

    // Nine o'clock, and the chair is empty.
    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London'));
    app(BookingService::class)->markNoShow($booking);

    $offer = SlotOffer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    // The assertion the whole fix exists for: this used to throw
    // OfferUnavailableException("Sorry, that slot was just taken.").
    $claimed = app(BookingService::class)->claimOffer($offer);

    expect($claimed->id)->not->toBe($booking->id)
        ->and($claimed->status)->toBe(BookingStatus::Confirmed)
        ->and($claimed->staff_id)->toBe($staff->id)
        ->and($claimed->starts_at->utc()->toIso8601String())->toBe($starts->toIso8601String())
        ->and($claimed->waitlist_entry_id)->toBe($entry->id)
        ->and($offer->fresh()->status)->toBe(SlotOfferStatus::Claimed)
        ->and($entry->fresh()->is_active)->toBeFalse()
        // The missed booking is untouched: it still happened, and the no-show
        // rate still counts it.
        ->and($booking->fresh()->status)->toBe(BookingStatus::NoShow);
});

/*
 * The half of this that occupancy cannot fix, recorded so nobody has to
 * rediscover it from a support ticket.
 *
 * A no-show is only markable once the appointment has started, and the engine
 * will not sell a start that is already behind `now + min_notice_hours`. So the
 * moment the owner takes to notice nobody came — a minute, ten, an hour — is
 * time the offered slot spends drifting into the past, and the offer goes out
 * anyway and cannot be taken. The test above passes because it marks the
 * no-show on the stroke of the hour with notice set to zero; this one is the
 * same salon fifteen minutes later.
 *
 * Fixing it properly means offering the *recoverable remainder* of the window
 * rather than its original start — the interval `FreedSlots::largestGap()`
 * already computes for the diary — and not offering at all when what is left is
 * shorter than the service. That is a change to what a slot offer means, so it
 * is not made here.
 *
 * When somebody does make it, this test should start failing. That is the
 * point of it.
 */
it('still cannot be claimed once the freed start has slipped into the past', function () {
    $salon = waitlistSalon();
    ['tenant' => $tenant, 'service' => $service] = $salon;
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    waiting($tenant, $service);

    $booking = app(BookingService::class)->create(
        $tenant, $service, $salon['staff'],
        Customer::factory()->create(['tenant_id' => $tenant->id]),
        $starts, BookingSource::Manual,
    );

    // A quarter of an hour late — the ordinary case, and the broken one.
    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:15:00', 'Europe/London'));
    app(BookingService::class)->markNoShow($booking);

    $offer = SlotOffer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    // The offer is sent — the customer gets a text — and then bounces.
    expect($offer->status)->toBe(SlotOfferStatus::Sent);
    expect(fn () => app(BookingService::class)->claimOffer($offer))
        ->toThrow(OfferUnavailableException::class);
});
