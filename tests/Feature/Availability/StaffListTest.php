<?php

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

function aStaffList(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-03-18 09:00:00', 'Europe/London'));

    $salon = aSalon(['staff' => ['name' => 'Rosa Adeyemi']]);
    $owner = $salon['staff'];
    $owner->forceFill(['role' => UserRole::Owner])->save();

    app(TenantContext::class)->set($salon['tenant']);
    AvailabilityRule::query()->where('user_id', $owner->id)->delete();
    app(TenantContext::class)->clear();

    return [$owner, $salon];
}

function giveHours(User $user, array $weekdays, string $start = '09:00:00', string $end = '17:00:00'): void
{
    foreach ($weekdays as $weekday) {
        AvailabilityRule::factory()->create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'weekday' => $weekday,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }
}

it('collapses a run of identical days into one phrase and names the days off', function () {
    [$owner] = aStaffList();

    giveHours($owner, [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday]);

    actingAsTenant($owner)
        ->get(route('staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Staff/Index')
            ->where('staff.0.initial', 'R')
            ->where('staff.0.role_label', 'Owner')
            ->where('staff.0.hours', 'Mon–Fri · 09:00–17:00 · closed Sat & Sun')
            ->where('staff.0.weekly_hours', '40 h'));
});

it('does not flatten a week whose days are not the same', function () {
    [$owner] = aStaffList();

    giveHours($owner, [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday]);
    giveHours($owner, [Weekday::Friday], '09:00:00', '13:00:00');

    actingAsTenant($owner)
        ->get(route('staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.0.hours', 'Mon–Thu · 09:00–17:00 · Fri · 09:00–13:00 · closed Sat & Sun')
            ->where('staff.0.weekly_hours', '36 h'));
});

it('says so plainly when nobody has set any hours', function () {
    [$owner] = aStaffList();

    actingAsTenant($owner)
        ->get(route('staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.0.hours', 'No hours set')
            ->where('staff.0.weekly_hours', null));
});

it('counts the week she is looking at, and not the appointments nobody is coming to', function () {
    [$owner, $salon] = aStaffList();

    giveHours($owner, [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday]);

    app(TenantContext::class)->set($salon['tenant']);
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);

    $make = function (string $day, BookingStatus $status) use ($salon, $owner, $customer) {
        Booking::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'staff_id' => $owner->id,
            'service_id' => $salon['service']->id,
            'customer_id' => $customer->id,
            'starts_at' => CarbonImmutable::parse($day.' 10:00:00', 'Europe/London')->utc(),
            'ends_at' => CarbonImmutable::parse($day.' 11:00:00', 'Europe/London')->utc(),
            'status' => $status,
            'price_at_booking' => 3500,
        ]);
    };

    $make('2026-03-16', BookingStatus::Completed);
    $make('2026-03-18', BookingStatus::Confirmed);
    $make('2026-03-20', BookingStatus::Pending);
    $make('2026-03-19', BookingStatus::Cancelled);
    $make('2026-03-17', BookingStatus::NoShow);
    $make('2026-03-24', BookingStatus::Confirmed);

    app(TenantContext::class)->clear();

    actingAsTenant($owner)
        ->get(route('staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('staff.0.booked_this_week', 3));
});

it('says which of two people is not taking bookings', function () {
    [$owner, $salon] = aStaffList();

    app(TenantContext::class)->set($salon['tenant']);
    $second = User::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Ines Duarte',
        'role' => UserRole::Staff,
        'is_bookable' => false,
        'is_active' => true,
    ]);
    app(TenantContext::class)->clear();

    actingAsTenant($owner)
        ->get(route('staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('staff', 2)
            ->where('staff.0.role_label', 'Owner')
            ->where('staff.1.name', $second->name)
            ->where('staff.1.role_label', 'Staff · not bookable')
            ->where('staff.1.booked_this_week', 0));
});
