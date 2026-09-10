<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Subject;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The list pages, against a list long enough for an N+1 to show.
 *
 * A page that eager-loads correctly costs the same number of queries whether it
 * renders three rows or thirty. A page that does not costs one more per row, so
 * these seed two sizes and assert the count does not move. That is the property
 * worth guarding: a literal ceiling ("no more than 14 queries") fails on every
 * unrelated change and gets raised until it means nothing.
 */

/**
 * Queries for one request, measured warm.
 *
 * The work runs twice and only the second is counted. A cold first request pays
 * for things that have nothing to do with the page — an availability cache
 * miss, a session row, a settings read — and measured cold the *bigger* list
 * came out two queries cheaper than the small one, which says nothing about
 * eager loading either way.
 */
function countQueries(callable $work): int
{
    $work();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $work();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function seedBookings(array $salon, int $count): void
{
    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    for ($i = 0; $i < $count; $i++) {
        $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);

        Booking::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'staff_id' => $salon['staff']->id,
            'service_id' => $salon['service']->id,
            'customer_id' => $customer->id,
            'subject_id' => Subject::factory()->create([
                'tenant_id' => $salon['tenant']->id,
                'customer_id' => $customer->id,
            ])->id,
            'starts_at' => $starts->addMinutes($i * 90),
            'ends_at' => $starts->addMinutes($i * 90 + 60),
            'status' => BookingStatus::Confirmed,
            'source' => BookingSource::Online,
        ]);
    }
}

function seedWaitlist(array $salon, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);

        WaitlistEntry::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'service_id' => $salon['service']->id,
            'customer_id' => $customer->id,
            'subject_id' => Subject::factory()->create([
                'tenant_id' => $salon['tenant']->id,
                'customer_id' => $customer->id,
            ])->id,
        ]);
    }
}

function owner(array $salon): User
{
    return User::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);
}

it('costs the same to list thirty bookings as three', function () {
    $salon = aSalon();
    $boss = owner($salon);

    seedBookings($salon, 3);
    $small = countQueries(fn () => actingAsTenant($boss)->get(route('bookings.index'))->assertOk());

    seedBookings($salon, 27);
    $large = countQueries(fn () => actingAsTenant($boss)->get(route('bookings.index'))->assertOk());

    expect($large)->toBe($small);
});

it('costs the same to list thirty waiting as three', function () {
    $salon = aSalon();
    $boss = owner($salon);

    seedWaitlist($salon, 3);
    $small = countQueries(fn () => actingAsTenant($boss)->get(route('waitlist.index'))->assertOk());

    seedWaitlist($salon, 27);
    $large = countQueries(fn () => actingAsTenant($boss)->get(route('waitlist.index'))->assertOk());

    expect($large)->toBe($small);
});

it('costs the same to list thirty customers as three', function () {
    $salon = aSalon();
    $boss = owner($salon);

    seedBookings($salon, 3);
    $small = countQueries(fn () => actingAsTenant($boss)->get(route('customers.index'))->assertOk());

    seedBookings($salon, 27);
    $large = countQueries(fn () => actingAsTenant($boss)->get(route('customers.index'))->assertOk());

    expect($large)->toBe($small);
});

it('costs the same to draw a day with thirty appointments as three', function () {
    $salon = aSalon();
    $boss = owner($salon);

    seedBookings($salon, 3);
    $small = countQueries(
        fn () => actingAsTenant($boss)->get(route('diary.index', ['date' => '2026-03-10']))->assertOk(),
    );

    seedBookings($salon, 27);
    $large = countQueries(
        fn () => actingAsTenant($boss)->get(route('diary.index', ['date' => '2026-03-10']))->assertOk(),
    );

    expect($large)->toBe($small);
});

it('costs the same to open a booking record whatever else is in the diary', function () {
    $salon = aSalon();
    $boss = owner($salon);

    seedBookings($salon, 3);
    // No tenant context in a test body, so the global scope would find nothing.
    $first = Booking::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->orderBy('id')
        ->firstOrFail();

    $small = countQueries(fn () => actingAsTenant($boss)->get(route('bookings.show', $first->id))->assertOk());

    seedBookings($salon, 27);
    $large = countQueries(fn () => actingAsTenant($boss)->get(route('bookings.show', $first->id))->assertOk());

    expect($large)->toBe($small);
});

it('costs the same to show the overdue list at thirty as at three', function () {
    $salon = aSalon();
    $boss = owner($salon);

    seedBookings($salon, 3);
    $small = countQueries(fn () => actingAsTenant($boss)->get(route('overdue.index'))->assertOk());

    seedBookings($salon, 27);
    $large = countQueries(fn () => actingAsTenant($boss)->get(route('overdue.index'))->assertOk());

    expect($large)->toBe($small);
});
