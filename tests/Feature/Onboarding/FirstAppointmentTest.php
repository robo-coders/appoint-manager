<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The last step of setting up hands over a diary with something in it.
 *
 * An empty diary on day one is the moment a salon owner decides this was a
 * mistake: twenty minutes of typing, and then a blank week. Every salon signing
 * up has a paper book with tomorrow already in it, so the final step asks for
 * one line of it.
 *
 * Optional throughout. Skipping it is the same flow it has always been, and
 * that is asserted here rather than assumed — a required field wearing the word
 * "optional" is the failure this suite exists to catch.
 */

/** A salon part-way through setup, with a service and a Monday. */
function aSalonSettingUp(): array
{
    $tenant = Tenant::factory()->onboardingIncomplete()->create(['timezone' => 'Europe/London']);

    app(TenantContext::class)->set($tenant);
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Full groom',
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'is_active' => true,
        'deposit_amount' => 0,
    ]);
    $service->staff()->attach($owner->id);
    app(TenantContext::class)->clear();

    return compact('tenant', 'owner', 'service');
}

/** Monday 09:00 next week, local, which is inside the hours posted below. */
function aMondayMorning(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-09-07 09:00:00', 'Europe/London');
}

/** @param  array<string, mixed>|null  $first */
function finishSetup(array $salon, ?array $first)
{
    return actingAsTenant($salon['owner'])->patch(route('onboarding.hours'), [
        'rules' => [
            ['user_id' => $salon['owner']->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
        ],
        'first_booking' => $first,
    ]);
}

beforeEach(fn () => test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'Europe/London')));

it('puts the first appointment in the diary and lands on its day', function () {
    $salon = aSalonSettingUp();

    $response = finishSetup($salon, [
        'customer_name' => 'Naomi Ellery',
        'customer_email' => 'naomi@example.com',
        'service_id' => $salon['service']->id,
        'staff_id' => $salon['owner']->id,
        'starts_at' => aMondayMorning()->format('Y-m-d\TH:i'),
    ]);

    // The diary, on the day the appointment is on — not on today, where it
    // would not be visible.
    $response->assertRedirect(route('diary.index', ['date' => '2026-09-07']));

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect($booking->source)->toBe(BookingSource::Manual)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->starts_at->timezone('Europe/London')->format('H:i'))->toBe('09:00');

    // A real customer, not a name on a row. The rest of the product can do
    // something with a customer and nothing with a string.
    expect(Customer::withoutGlobalScopes()->where('id', $booking->customer_id)->value('name'))
        ->toBe('Naomi Ellery');
});

it('finishes setup either way', function () {
    $salon = aSalonSettingUp();

    finishSetup($salon, null)->assertRedirect(route('diary.index'));

    expect($salon['tenant']->fresh()->hasCompletedOnboarding())->toBeTrue()
        ->and(Booking::withoutGlobalScopes()->count())->toBe(0);
});

/*
 * The ordering that matters. `BookingService` checks the slot against the
 * availability rules, and at this exact moment the rules being checked are the
 * ones in the same request. Written the other way round, the first appointment
 * is refused for every salon that has just told us when it opens.
 */
it('accepts an appointment inside the hours posted in the same request', function () {
    $salon = aSalonSettingUp();

    finishSetup($salon, [
        'customer_name' => 'Naomi Ellery',
        'customer_email' => 'naomi@example.com',
        'service_id' => $salon['service']->id,
        'staff_id' => $salon['owner']->id,
        'starts_at' => aMondayMorning()->format('Y-m-d\TH:i'),
    ])->assertRedirect(route('diary.index', ['date' => '2026-09-07']));

    expect(Booking::withoutGlobalScopes()->count())->toBe(1);
});

it('says so when the time is outside the hours just set, rather than failing silently', function () {
    $salon = aSalonSettingUp();

    // 21:00 on the Monday. The rules posted alongside say 09:00-17:00.
    $response = finishSetup($salon, [
        'customer_name' => 'Naomi Ellery',
        'customer_email' => 'naomi@example.com',
        'service_id' => $salon['service']->id,
        'staff_id' => $salon['owner']->id,
        'starts_at' => aMondayMorning()->setTime(21, 0)->format('Y-m-d\TH:i'),
    ]);

    $response->assertSessionHasErrors('first_booking');

    expect(Booking::withoutGlobalScopes()->count())->toBe(0);
});

/*
 * Half an appointment is a mistake, not a skip. Sending a name with no time has
 * to be rejected on the field rather than quietly dropped — otherwise the
 * person who typed a name and landed on an empty diary has no way to know why.
 */
it('rejects a half-filled appointment instead of ignoring it', function () {
    $salon = aSalonSettingUp();

    finishSetup($salon, ['customer_name' => 'Naomi Ellery'])
        ->assertSessionHasErrors([
            'first_booking.customer_email',
            'first_booking.service_id',
            'first_booking.staff_id',
            'first_booking.starts_at',
        ]);
});

/*
 * Tenancy. `ExistsForTenant` rather than `exists`, so a service id belonging to
 * a different salon is a validation failure and not a cross-tenant booking.
 */
it('refuses another salon’s service', function () {
    $salon = aSalonSettingUp();
    $other = aSalonSettingUp();

    finishSetup($salon, [
        'customer_name' => 'Naomi Ellery',
        'customer_email' => 'naomi@example.com',
        'service_id' => $other['service']->id,
        'staff_id' => $salon['owner']->id,
        'starts_at' => aMondayMorning()->format('Y-m-d\TH:i'),
    ])->assertSessionHasErrors('first_booking.service_id');

    expect(Booking::withoutGlobalScopes()->count())->toBe(0);
});

it('offers tomorrow at nine, in the salon’s timezone, as the default', function () {
    $salon = aSalonSettingUp();
    $salon['tenant']->forceFill(['timezone' => 'Pacific/Auckland'])->save();

    actingAsTenant($salon['owner'])
        ->get(route('onboarding.show', ['step' => 'hours']))
        ->assertInertia(fn ($page) => $page->where(
            'firstBookingDefault',
            CarbonImmutable::now('Pacific/Auckland')->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'),
        ));
});
