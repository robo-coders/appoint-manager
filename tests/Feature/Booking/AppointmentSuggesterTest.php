<?php

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\AppointmentSuggester;
use App\Services\Booking\ReasonKey;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;

/*
 * Two kinds of test in here, on purpose.
 *
 * The **demo tenant** ones are the honest ones: 72 clients and six weeks of
 * real booking history, which is the only way to find out whether "your usual
 * Tuesday" is ever actually true of anybody. A four-booking fixture can tell
 * you the code runs; it cannot tell you the ranking is right.
 *
 * The **fixture** ones cover the shapes the demo tenant does not contain —
 * a salon with one groomer, a groomer who has left, a diary with nothing free
 * at all. Those are edge cases by definition, so building them is the more
 * honest test.
 */

/**
 * A salon on a fixed Wednesday morning.
 *
 * The clock is moved rather than injected: `AvailabilityEngine` reads
 * `now()` itself for minimum notice and for the booking horizon, so a `$now`
 * passed only to the suggester would bound the query window while the engine
 * still filtered against the real time. Travelling makes the two agree.
 *
 * @return array{tenant: Tenant, staff: User, service: Service, customer: Customer}
 */
function aSuggesterSalon(array $overrides = []): array
{
    test()->travelTo(CarbonImmutable::parse('2026-08-26 08:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create(array_merge([
        'timezone' => 'Europe/London',
        'settings' => ['booking' => ['min_notice_hours' => 0, 'horizon_days' => 60]],
    ], $overrides['tenant'] ?? []));

    $staff = User::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'name' => 'Ana Duarte',
        'is_bookable' => true,
        'is_active' => true,
    ], $overrides['staff'] ?? []));

    $service = Service::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'name' => 'Full groom',
        'duration_minutes' => 90,
        'buffer_minutes' => 0,
        'is_active' => true,
        'price' => 4500,
        'deposit_amount' => 1500,
        'suggested_interval_days' => 42,
    ], $overrides['service'] ?? []));

    $service->staff()->attach($staff->id);

    // Open every weekday. Weekends are added by the tests that want them.
    foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $day) {
        AvailabilityRule::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $staff->id,
            'weekday' => $day,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);
    }

    return [
        'tenant' => $tenant,
        'staff' => $staff,
        'service' => $service,
        'customer' => Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Naomi Ellery']),
    ];
}

function aPastBooking(array $salon, string $when, ?User $staff = null): Booking
{
    $starts = CarbonImmutable::parse($when, 'Europe/London');

    return Booking::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => ($staff ?? $salon['staff'])->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $salon['customer']->id,
        'starts_at' => $starts->utc(),
        'ends_at' => $starts->addMinutes(90)->utc(),
        'status' => BookingStatus::Completed,
    ]);
}

function suggester(): AppointmentSuggester
{
    return app(AppointmentSuggester::class);
}

/**
 * The demo tenant, seeded for real: 4 staff, 9 services, 72 clients, six weeks
 * of history and a deliberately awkward today.
 *
 * `DemoDataSeeder` fills a salon that already exists rather than creating one,
 * so the owner has to be here before it runs.
 */
function aDemoTenant(): Tenant
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London', 'name' => 'paw']);
    User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::Owner]);

    (new DemoDataSeeder)->forTenant($tenant);
    app(TenantContext::class)->clear();

    return $tenant->refresh();
}

// ---------------------------------------------------------------------------
// New customer
// ---------------------------------------------------------------------------

it('gives a new customer the first available slot, and says so', function () {
    $salon = aSuggesterSalon();
    // It is 08:00 on Wednesday 26 August. The salon opens at 09:00.
    $suggestion = suggester()->suggest($salon['tenant'], null, $salon['service']);

    expect($suggestion->returning)->toBeFalse();
    expect($suggestion->intervalDays)->toBeNull();
    expect($suggestion->primary->reasonKey)->toBe(ReasonKey::FirstAvailable);
    expect($suggestion->primary->reason)->toBe('First available');
    expect($suggestion->primary->startsAt->timezone('Europe/London')->format('Y-m-d H:i'))->toBe('2026-08-26 09:00');
    expect($suggestion->primary->staff->id)->toBe($salon['staff']->id);
    expect($suggestion->primary->subject)->toBeNull();
});

// ---------------------------------------------------------------------------
// Returning customer
// ---------------------------------------------------------------------------

it('proposes a returning customer their usual weekday, at their own interval', function () {
    $salon = aSuggesterSalon();

    // Three Tuesdays, four weeks apart. Interval = 28 days, usual day = Tuesday.
    aPastBooking($salon, '2026-05-26 10:00:00');
    aPastBooking($salon, '2026-06-23 10:00:00');
    aPastBooking($salon, '2026-07-21 10:00:00');

    $suggestion = suggester()->suggest($salon['tenant'], $salon['customer']);

    expect($suggestion->returning)->toBeTrue();
    expect($suggestion->intervalDays)->toBe(28);
    expect($suggestion->primary->reasonKey)->toBe(ReasonKey::UsualDay);
    expect($suggestion->primary->reason)->toBe('Your usual Tuesday');
    // 28 days after 26 August is 23 September, a Wednesday; the first Tuesday
    // at or after that is 29 September.
    expect($suggestion->primary->startsAt->timezone('Europe/London')->isoWeekday())->toBe(2);
    expect($suggestion->primary->startsAt->timezone('Europe/London')->format('Y-m-d'))->toBe('2026-09-29');
    expect($suggestion->primary->staff->id)->toBe($salon['staff']->id);
    // Same service as last time, without being asked.
    expect($suggestion->primary->service->id)->toBe($salon['service']->id);
});

it('takes the median gap, so one long absence does not move the rhythm', function () {
    $salon = aSuggesterSalon();

    // Gaps of 28 and 140 days. The median of two is their mean — 84 — which is
    // still pulled by the outlier, and that is the honest limit of two gaps.
    aPastBooking($salon, '2026-03-03 10:00:00');
    aPastBooking($salon, '2026-07-21 10:00:00');
    aPastBooking($salon, '2026-08-18 10:00:00');

    $suggestion = suggester()->suggest($salon['tenant'], $salon['customer']);

    expect($suggestion->intervalDays)->toBe(84);
});

it('falls back to the service interval for a customer with only one visit', function () {
    $salon = aSuggesterSalon(['service' => ['suggested_interval_days' => 21]]);
    aPastBooking($salon, '2026-08-04 10:00:00');

    $suggestion = suggester()->suggest($salon['tenant'], $salon['customer']);

    expect($suggestion->returning)->toBeTrue();
    // No rhythm of their own to report.
    expect($suggestion->intervalDays)->toBeNull();
    /*
     * 21 days on from 26 August is 16 September. One visit is not a habit —
     * neither a weekday nor a time of day can honestly be called "usual" off a
     * single data point — so the strongest true claim left is that they are
     * about due and their groomer is free.
     */
    expect($suggestion->primary->startsAt->timezone('Europe/London')->format('Y-m-d'))->toBe('2026-09-16');
    expect($suggestion->primary->reasonKey)->toBe(ReasonKey::DueNow);
    expect($suggestion->primary->reason)->toBe('About due, and Ana is free');
});

it('will not call a one-in-three coincidence a usual day', function () {
    $salon = aSuggesterSalon();

    // Monday, Wednesday, Friday. No majority, so no habit to claim.
    aPastBooking($salon, '2026-06-01 14:00:00');
    aPastBooking($salon, '2026-07-01 14:00:00');
    aPastBooking($salon, '2026-08-07 14:00:00');

    $suggestion = suggester()->suggest($salon['tenant'], $salon['customer']);

    expect($suggestion->primary->reasonKey)->not->toBe(ReasonKey::UsualDay);
    // Their time of day survives as a claim even when their weekday does not.
    expect($suggestion->primary->reasonKey)->toBe(ReasonKey::UsualTime);
    expect($suggestion->primary->reason)->toBe('Around your usual time');
});

// ---------------------------------------------------------------------------
// Their usual staff is unavailable
// ---------------------------------------------------------------------------

it('offers their groomer sooner when nothing is free at their usual interval', function () {
    // Horizon of one week, interval of six: nothing at or after they are due.
    $salon = aSuggesterSalon([
        'tenant' => ['settings' => ['booking' => ['min_notice_hours' => 0, 'horizon_days' => 7]]],
    ]);

    aPastBooking($salon, '2026-06-16 10:00:00');
    aPastBooking($salon, '2026-07-14 10:00:00');
    aPastBooking($salon, '2026-08-11 10:00:00');

    $suggestion = suggester()->suggest($salon['tenant'], $salon['customer']);

    expect($suggestion->primary->reasonKey)->toBe(ReasonKey::SoonestWithStaff);
    expect($suggestion->primary->reason)->toBe('Soonest with Ana');
    expect($suggestion->primary->staff->id)->toBe($salon['staff']->id);
});

it('falls back to anyone, and says first available, when their groomer has left', function () {
    $salon = aSuggesterSalon();

    $gone = User::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Marek Kowalski',
        'is_bookable' => true,
        'is_active' => false,
    ]);

    aPastBooking($salon, '2026-06-16 10:00:00', $gone);
    aPastBooking($salon, '2026-07-14 10:00:00', $gone);
    aPastBooking($salon, '2026-08-11 10:00:00', $gone);

    $suggestion = suggester()->suggest($salon['tenant'], $salon['customer']);

    expect($suggestion->returning)->toBeTrue();
    expect($suggestion->primary->reasonKey)->toBe(ReasonKey::FirstAvailable);
    expect($suggestion->primary->reason)->toBe('First available');
    // Not the groomer who has gone.
    expect($suggestion->primary->staff->id)->toBe($salon['staff']->id);
});

// ---------------------------------------------------------------------------
// No availability at all
// ---------------------------------------------------------------------------

it('proposes nothing at all when the salon has no availability', function () {
    $salon = aSuggesterSalon();
    AvailabilityRule::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->delete();

    $suggestion = suggester()->suggest($salon['tenant'], null, $salon['service']);

    expect($suggestion->isEmpty())->toBeTrue();
    expect($suggestion->primary)->toBeNull();
    expect($suggestion->alternatives)->toBe([]);
});

it('proposes nothing when the service has no staff who can do it', function () {
    $salon = aSuggesterSalon();
    $salon['service']->staff()->detach();

    expect(suggester()->suggest($salon['tenant'], null, $salon['service'])->isEmpty())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Single-staff salon
// ---------------------------------------------------------------------------

it('works in a one-groomer salon, where every proposal is the same person', function () {
    $salon = aSuggesterSalon();

    $suggestion = suggester()->suggest($salon['tenant'], null, $salon['service']);

    $all = array_merge([$suggestion->primary], $suggestion->alternatives);

    expect($all)->toHaveCount(4);
    foreach ($all as $proposal) {
        expect($proposal->staff->id)->toBe($salon['staff']->id);
        expect($proposal->reason)->not->toBe('');
    }
});

// ---------------------------------------------------------------------------
// The spread rule
// ---------------------------------------------------------------------------

it('never proposes three consecutive slots on one morning', function () {
    $salon = aSuggesterSalon();

    $suggestion = suggester()->suggest($salon['tenant'], null, $salon['service']);

    $buckets = array_map(
        fn ($proposal) => $proposal->bucket('Europe/London'),
        array_merge([$suggestion->primary], $suggestion->alternatives),
    );

    // Four proposals, four distinct date-and-half-of-day buckets. This is the
    // assertion that would fail on a naive "next three slots".
    expect($buckets)->toHaveCount(4);
    expect(array_unique($buckets))->toHaveCount(4);

    $dates = array_unique(array_map(fn (string $b) => explode('|', $b)[0], $buckets));
    expect(count($dates))->toBeGreaterThanOrEqual(2);
});

it('offers a weekend only when the salon opens at weekends', function () {
    $salon = aSuggesterSalon();
    $weekdaysOnly = suggester()->suggest($salon['tenant'], null, $salon['service']);
    $keys = array_map(fn ($p) => $p->reasonKey, $weekdaysOnly->alternatives);
    expect($keys)->not->toContain(ReasonKey::Weekend);

    AvailabilityRule::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'user_id' => $salon['staff']->id,
        'weekday' => Weekday::Saturday,
        'start_time' => '10:00:00',
        'end_time' => '15:00:00',
    ]);

    $withSaturday = suggester()->suggest($salon['tenant'], null, $salon['service']);
    $weekend = collect($withSaturday->alternatives)->firstWhere('reasonKey', ReasonKey::Weekend);

    expect($weekend)->not->toBeNull();
    expect($weekend->startsAt->timezone('Europe/London')->isoWeekday())->toBe(6);
    expect($weekend->reason)->toBe('Saturday morning');
});

// ---------------------------------------------------------------------------
// Every reason string, in one place
// ---------------------------------------------------------------------------

it('gives every proposal a reason, and every reason a distinct sentence', function () {
    $salon = aSuggesterSalon();

    aPastBooking($salon, '2026-05-26 10:00:00');
    aPastBooking($salon, '2026-06-23 10:00:00');
    aPastBooking($salon, '2026-07-21 10:00:00');

    $suggestion = suggester()->suggest($salon['tenant'], $salon['customer']);

    $all = array_merge([$suggestion->primary], $suggestion->alternatives);
    $reasons = array_map(fn ($p) => $p->reason, $all);

    foreach ($reasons as $reason) {
        expect($reason)->not->toBe('');
        // Sentence case, no shouting. `check:design` enforces the same rule in
        // the templates; a phrase built in PHP has to hold itself to it.
        expect($reason)->not->toMatch('/[A-Z]{2,}|!/');
        expect($reason[0])->toBe(strtoupper($reason[0]));
    }

    expect($reasons)->toEqual([
        'Your usual Tuesday',
        'Tuesday, later',
        'Wednesday morning',
        'Last one this week',
    ]);
});

// ---------------------------------------------------------------------------
// The demo tenant
// ---------------------------------------------------------------------------

it('proposes a real returning client their usual pattern, on the demo tenant', function () {
    $tenant = aDemoTenant();

    // The client with the most history, which is who the suggester has the most
    // to say about.
    $customerId = Booking::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->whereIn('status', [BookingStatus::Completed->value, BookingStatus::Confirmed->value])
        ->selectRaw('customer_id, count(*) as n')
        ->groupBy('customer_id')
        ->orderByDesc('n')
        ->value('customer_id');

    $customer = Customer::withoutGlobalScopes()->findOrFail($customerId);

    $suggestion = suggester()->suggest($tenant, $customer);

    expect($suggestion->returning)->toBeTrue();
    expect($suggestion->isEmpty())->toBeFalse();
    // Same service, same staff, same subject as last time — read off real history,
    // not asserted from a fixture that was built to make it true.
    $last = Booking::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('customer_id', $customer->id)
        ->where('starts_at', '<', now())
        ->whereIn('status', [BookingStatus::Completed->value, BookingStatus::Confirmed->value])
        ->orderByDesc('starts_at')
        ->orderByDesc('id')
        ->first();

    expect($suggestion->primary->service->id)->toBe($last->service_id);
    expect($suggestion->primary->subject?->id)->toBe($last->subject_id);
    expect($suggestion->primary->reason)->not->toBe('');

    // And the spread rule holds against a genuinely busy six weeks.
    $buckets = array_map(
        fn ($p) => $p->bucket('Europe/London'),
        array_merge([$suggestion->primary], $suggestion->alternatives),
    );
    expect(array_unique($buckets))->toHaveCount(count($buckets));
})->group('demo');

it('proposes something to a brand new client on the demo tenant, with a reason', function () {
    $tenant = aDemoTenant();

    $suggestion = suggester()->suggest($tenant, null);

    expect($suggestion->returning)->toBeFalse();
    expect($suggestion->primary->reasonKey)->toBe(ReasonKey::FirstAvailable);
    expect($suggestion->alternatives)->toHaveCount(3);
    expect($suggestion->primary->startsAt->gte(now()))->toBeTrue();
})->group('demo');

it('treats a sub-weekly median gap as no rhythm at all', function () {
    // Two dogs, brought on consecutive days. A one-day median is not a habit.
    $salon = aSuggesterSalon(['service' => ['suggested_interval_days' => 28]]);
    aPastBooking($salon, '2026-08-03 10:00:00');
    aPastBooking($salon, '2026-08-04 10:00:00');
    aPastBooking($salon, '2026-08-05 10:00:00');

    $suggestion = suggester()->suggest($salon['tenant'], $salon['customer']);

    expect($suggestion->intervalDays)->toBeNull();
    // 28 days on from 26 August, not tomorrow.
    expect($suggestion->primary->startsAt->timezone('Europe/London')->format('Y-m-d'))->toBe('2026-09-23');
});

it('never shows the same alternative label twice', function () {
    $salon = aSuggesterSalon();

    // Saturdays only, so every weekend alternative wants the same two words.
    AvailabilityRule::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->delete();
    AvailabilityRule::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'user_id' => $salon['staff']->id,
        'weekday' => Weekday::Saturday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    $suggestion = suggester()->suggest($salon['tenant'], null, $salon['service']);
    $reasons = array_map(fn ($p) => $p->reason, array_merge([$suggestion->primary], $suggestion->alternatives));

    expect($reasons)->toHaveCount(4);
    expect(array_unique($reasons))->toHaveCount(4);
});
