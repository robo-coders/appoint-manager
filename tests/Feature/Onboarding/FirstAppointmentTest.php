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

function aMondayMorning(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-09-07 09:00:00', 'Europe/London');
}

/** @param  array<string, mixed>|null  $first */
function finishSetup(array $salon, ?array $first)
{
    $tenant = $salon['tenant'];

    actingAsTenant($salon['owner'])->patch(route('onboarding.basics'), [
        'name' => $tenant->name,
        'slug' => $tenant->slug,
        'type' => $tenant->type,
        'hours' => aWeekOpenOn(1),
    ])->assertSessionHasNoErrors();

    return actingAsTenant($salon['owner'])->post(route('onboarding.complete'), [
        'slug' => $tenant->slug,
        'first_booking' => $first,
    ]);
}

/** @return list<array{weekday: int, open: bool, start_time: string, end_time: string}> */
function aWeekOpenOn(int ...$weekdays): array
{
    return collect(range(1, 7))
        ->map(fn (int $day) => [
            'weekday' => $day,
            'open' => in_array($day, $weekdays, true),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ])
        ->all();
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

    $response->assertRedirect(route('diary.index', ['date' => '2026-09-07']));

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect($booking->source)->toBe(BookingSource::Manual)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->starts_at->timezone('Europe/London')->format('H:i'))->toBe('09:00');

    expect(Customer::withoutGlobalScopes()->where('id', $booking->customer_id)->value('name'))
        ->toBe('Naomi Ellery');
});

it('finishes setup either way', function () {
    $salon = aSalonSettingUp();

    finishSetup($salon, null)->assertRedirect(route('diary.index'));

    expect($salon['tenant']->fresh()->hasCompletedOnboarding())->toBeTrue()
        ->and(Booking::withoutGlobalScopes()->count())->toBe(0);
});

it('accepts an appointment inside the hours set on step one', function () {
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

it('rejects a half-filled appointment instead of ignoring it', function () {
    $salon = aSalonSettingUp();

    finishSetup($salon, ['customer_name' => 'Naomi Ellery'])
        ->assertSessionHasErrors([
            'first_booking.service_id',
            'first_booking.staff_id',
            'first_booking.starts_at',
        ])
        ->assertSessionDoesntHaveErrors('first_booking.customer_email');
});

it('accepts a first appointment that is only a name', function () {
    $salon = aSalonSettingUp();

    finishSetup($salon, [
        'customer_name' => 'Naomi Ellery',
        'service_id' => $salon['service']->id,
        'staff_id' => $salon['owner']->id,
        'starts_at' => aMondayMorning()->format('Y-m-d\TH:i'),
    ])->assertRedirect(route('diary.index', ['date' => '2026-09-07']));

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole()->email)
        ->toBeNull();
});

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
        ->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page->where(
            'firstBookingDefault',
            CarbonImmutable::now('Pacific/Auckland')->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'),
        ));
});
