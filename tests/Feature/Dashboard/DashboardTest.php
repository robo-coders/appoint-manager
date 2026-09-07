<?php

use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\PreferredTime;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\SlotOffer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;

/**
 * A salon on a fixed Wednesday, so "today", "this month" and "last month" all
 * mean the same thing on every run.
 *
 * @return array{tenant: Tenant, user: User, staff: User, service: Service, customer: Customer}
 */
function aDashboardSalon(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-08-19 13:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'name' => 'Marek Kowalski']);
    $service = Service::factory()->create(['tenant_id' => $tenant->id, 'price' => 4000, 'duration_minutes' => 90]);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Tomas Marlow']);

    return compact('tenant', 'user', 'staff', 'service', 'customer');
}

/** @param  array<string, mixed>  $overrides */
function aDashboardBooking(array $salon, string $from, string $to, array $overrides = []): Booking
{
    return Booking::factory()->create(array_merge([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $salon['customer']->id,
        'starts_at' => CarbonImmutable::parse($from, 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse($to, 'Europe/London')->utc(),
        'status' => BookingStatus::Confirmed,
        'price_at_booking' => 4000,
    ], $overrides));
}

it('counts recovered revenue from bookings that carry a waitlist entry', function () {
    $salon = aDashboardSalon();

    $entry = WaitlistEntry::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $salon['customer']->id,
        'service_id' => $salon['service']->id,
    ]);

    aDashboardBooking($salon, '2026-08-19 15:00:00', '2026-08-19 16:30:00', ['waitlist_entry_id' => $entry->id]);
    // Same month, no waitlist entry: ordinary revenue, not recovered revenue.
    aDashboardBooking($salon, '2026-08-20 09:00:00', '2026-08-20 10:30:00');

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('band.recovered.value', '£40.00')
            ->where('band.recovered.count', 1)
            ->where('band.recovered.month', 'August'));
});

it('does not count a recovered slot that was cancelled again', function () {
    $salon = aDashboardSalon();

    $entry = WaitlistEntry::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $salon['customer']->id,
        'service_id' => $salon['service']->id,
    ]);

    aDashboardBooking($salon, '2026-08-21 15:00:00', '2026-08-21 16:30:00', [
        'waitlist_entry_id' => $entry->id,
        'status' => BookingStatus::Cancelled,
    ]);

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('band.recovered.value', '£0.00')->where('band.recovered.count', 0));
});

it('holds deposits only against appointments that have not happened', function () {
    $salon = aDashboardSalon();

    aDashboardBooking($salon, '2026-08-25 09:00:00', '2026-08-25 10:30:00', [
        'deposit_status' => DepositStatus::Paid,
        'deposit_at_booking' => 1500,
    ]);
    // Already happened — the money is not held any more, it is earned.
    aDashboardBooking($salon, '2026-08-12 09:00:00', '2026-08-12 10:30:00', [
        'deposit_status' => DepositStatus::Paid,
        'deposit_at_booking' => 1500,
    ]);

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('band.deposits.value', '£15.00')
            ->where('band.deposits.count', 1));
});

it('rates no-shows against finished appointments only, and compares with last month', function () {
    $salon = aDashboardSalon();

    // This month: one no-show in four finished = 25.0%.
    aDashboardBooking($salon, '2026-08-03 09:00:00', '2026-08-03 10:00:00', ['status' => BookingStatus::NoShow]);
    foreach (['2026-08-04', '2026-08-05', '2026-08-06'] as $day) {
        aDashboardBooking($salon, $day.' 09:00:00', $day.' 10:00:00', ['status' => BookingStatus::Completed]);
    }
    // Still to come: not finished, so it must not dilute the rate.
    aDashboardBooking($salon, '2026-08-28 09:00:00', '2026-08-28 10:00:00');

    // Last month: one no-show in two = 50.0%.
    aDashboardBooking($salon, '2026-07-03 09:00:00', '2026-07-03 10:00:00', ['status' => BookingStatus::NoShow]);
    aDashboardBooking($salon, '2026-07-04 09:00:00', '2026-07-04 10:00:00', ['status' => BookingStatus::Completed]);

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('band.no_shows.value', '25.0%')
            ->where('band.no_shows.previous', '50.0%')
            ->where('band.no_shows.previous_month', 'July')
            ->where('band.no_shows.direction', 'down'));
});

it('has no no-show rate at all when nothing has finished', function () {
    $salon = aDashboardSalon();
    aDashboardBooking($salon, '2026-08-28 09:00:00', '2026-08-28 10:00:00');

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('band.no_shows.value', '—')->where('band.no_shows.previous', null));
});

/*
 * The bug: both this screen and the diary filtered cancelled bookings out of
 * their queries, so the one row worth acting on today never rendered.
 */
it('shows a cancelled booking that left a gap as a freed slot', function () {
    $salon = aDashboardSalon();

    $freed = aDashboardBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', [
        'status' => BookingStatus::Cancelled,
        'deposit_status' => DepositStatus::Refunded,
    ]);

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('today', 1)
            ->where('today.0.id', $freed->id)
            ->where('today.0.time', '15:30')
            ->where('today.0.freed.minutes', 90)
            ->where('today.0.freed.deposit_kept', false));
});

it('counts the people who would actually be texted, and the offers already out', function () {
    $salon = aDashboardSalon();

    $freed = aDashboardBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', [
        'status' => BookingStatus::Cancelled,
    ]);

    // Wednesday afternoon, this service: a match.
    $match = WaitlistEntry::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $salon['customer']->id,
        'service_id' => $salon['service']->id,
        'preferred_days' => [3],
        'preferred_times' => PreferredTime::Afternoon,
        'is_active' => true,
    ]);

    // Saturday mornings only: wants this service, but not this slot.
    WaitlistEntry::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $salon['customer']->id,
        'service_id' => $salon['service']->id,
        'preferred_days' => [6],
        'preferred_times' => PreferredTime::Morning,
        'is_active' => true,
    ]);

    SlotOffer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'waitlist_entry_id' => $match->id,
        'service_id' => $salon['service']->id,
        'staff_id' => $salon['staff']->id,
        'starts_at' => $freed->starts_at,
        'ends_at' => $freed->ends_at,
        'expires_at' => CarbonImmutable::now()->addHour(),
    ]);

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('today.0.freed.waiting', 1)
            ->where('today.0.freed.offers_sent', 1));
});

it('does not call a cancellation freed when the hour has been refilled end to end', function () {
    $salon = aDashboardSalon();

    aDashboardBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', ['status' => BookingStatus::Cancelled]);
    // Somebody took the whole thing. There is nothing left to sell.
    $replacement = aDashboardBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00');

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('today', 1)
            ->where('today.0.id', $replacement->id)
            ->where('today.0.freed', null));
});

/*
 * The bug the demo tenant caught: treating *any* overlap as a refill made
 * Marek's 15:30 freed slot — the one with three people waiting for it — vanish
 * off both screens, because his own 16:30 appointment clipped its tail.
 */
it('measures what is genuinely still open when a refill covers only part of the hour', function () {
    $salon = aDashboardSalon();

    $freed = aDashboardBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', [
        'status' => BookingStatus::Cancelled,
    ]);
    // Takes the last half hour. An hour of it is still sellable.
    aDashboardBooking($salon, '2026-08-19 16:30:00', '2026-08-19 17:15:00');

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('today', 2)
            ->where('today.0.id', $freed->id)
            ->where('today.0.freed.minutes', 60));
});

it('drops a cancellation whose remaining gap is too short to sell', function () {
    $salon = aDashboardSalon();

    aDashboardBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', ['status' => BookingStatus::Cancelled]);
    // Ten minutes left at the front, which is not an appointment.
    $replacement = aDashboardBooking($salon, '2026-08-19 15:40:00', '2026-08-19 17:00:00');

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('today', 1)
            ->where('today.0.id', $replacement->id));
});

it('does not call a cancellation freed once its slot has passed', function () {
    $salon = aDashboardSalon();

    // It is 13:00. This one is over.
    aDashboardBooking($salon, '2026-08-19 09:00:00', '2026-08-19 10:30:00', ['status' => BookingStatus::Cancelled]);

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('today', 1)->where('today.0.freed', null));
});

it('marks the appointment happening right now and gives it the extra line', function () {
    $salon = aDashboardSalon();

    // It is 13:00.
    aDashboardBooking($salon, '2026-08-19 12:45:00', '2026-08-19 14:15:00', ['deposit_status' => DepositStatus::Paid]);

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('today.0.current', true)
            ->where('today.0.past', false)
            ->where('today.0.detail', 'In the chair 15 min · deposit paid · first visit'));
});

it('names the staff who are in today', function () {
    $salon = aDashboardSalon();
    aDashboardBooking($salon, '2026-08-19 15:00:00', '2026-08-19 16:30:00');

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('heading.staff_today', 'Marek in today')
            ->where('heading.date', 'Wednesday 19 August'));
});

/*
 * The headline and the panel beside it.
 *
 * The no-show rate leads the screen now, and a rate on its own says nothing —
 * `change` is the movement it is judged against, and the sign is what decides
 * whether the arrow is ink or danger. It is asserted here rather than looked at
 * because a `+` that should be a `-` is a screen telling an owner the opposite
 * of the truth.
 */
it('states the movement in the no-show rate, signed', function () {
    $salon = aDashboardSalon();

    // August: one missed of four finished = 25.0%.
    aDashboardBooking($salon, '2026-08-03 09:00:00', '2026-08-03 10:00:00', ['status' => BookingStatus::NoShow]);
    foreach (['2026-08-04', '2026-08-05', '2026-08-06'] as $day) {
        aDashboardBooking($salon, $day.' 09:00:00', $day.' 10:00:00', ['status' => BookingStatus::Completed]);
    }

    // July: one missed of two finished = 50.0%. So the rate has halved.
    aDashboardBooking($salon, '2026-07-06 09:00:00', '2026-07-06 10:00:00', ['status' => BookingStatus::NoShow]);
    aDashboardBooking($salon, '2026-07-07 09:00:00', '2026-07-07 10:00:00', ['status' => BookingStatus::Completed]);

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('band.no_shows.value', '25.0%')
            ->where('band.no_shows.direction', 'down')
            ->where('band.no_shows.change', '-25.0'));
});

it('has no movement to state when there is no month to compare with', function () {
    $salon = aDashboardSalon();

    aDashboardBooking($salon, '2026-08-03 09:00:00', '2026-08-03 10:00:00', ['status' => BookingStatus::Completed]);

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('band.no_shows.change', null));
});

it('aggregates what is waiting on her into one panel', function () {
    $salon = aDashboardSalon();

    // Two deposits requested and not paid, the older one four days ago.
    $old = aDashboardBooking($salon, '2026-08-25 09:00:00', '2026-08-25 10:30:00', [
        'deposit_status' => DepositStatus::Required,
        'deposit_at_booking' => 1000,
    ]);
    $old->forceFill(['created_at' => CarbonImmutable::parse('2026-08-15 09:00:00', 'Europe/London')->utc()])->save();

    aDashboardBooking($salon, '2026-08-26 09:00:00', '2026-08-26 10:30:00', [
        'deposit_status' => DepositStatus::Required,
        'deposit_at_booking' => 1500,
    ]);

    // Already happened: she cannot chase a deposit for an appointment that is over.
    aDashboardBooking($salon, '2026-08-01 09:00:00', '2026-08-01 10:30:00', [
        'deposit_status' => DepositStatus::Required,
        'deposit_at_booking' => 9900,
    ]);

    $entry = WaitlistEntry::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $salon['customer']->id,
        'service_id' => $salon['service']->id,
        'is_active' => true,
    ]);
    $entry->forceFill(['created_at' => CarbonImmutable::parse('2026-08-12 13:00:00', 'Europe/London')->utc()])->save();

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attention.deposits.count', 2)
            ->where('attention.deposits.value', '£25.00')
            ->where('attention.deposits.oldest_days', 4)
            ->where('attention.waitlist.count', 1)
            ->where('attention.waitlist.longest_days', 7));
});

it('says nothing is waiting on her when nothing is', function () {
    $salon = aDashboardSalon();

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attention.deposits.count', 0)
            ->where('attention.deposits.oldest_days', null)
            ->where('attention.waitlist.count', 0));
});

/*
 * "Closed today" and "nothing booked" are different facts, and the empty panel
 * says which. Read off the opening hours, so a salon that never works Saturdays
 * is never told to go and fill one.
 */
it('knows the shop is shut today and when it opens next', function () {
    $salon = aDashboardSalon();

    // Wednesday is the 19th. Open Mondays and Fridays only.
    foreach ([Weekday::Monday, Weekday::Friday] as $weekday) {
        AvailabilityRule::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'user_id' => $salon['staff']->id,
            'weekday' => $weekday,
        ]);
    }

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('diary.open_today', false)
            ->where('diary.weekday_plural', 'Wednesdays')
            ->where('diary.next_open.date', '2026-08-21')
            ->where('diary.next_open.label', 'Friday 21 August'));
});

it('knows the shop is open today', function () {
    $salon = aDashboardSalon();

    AvailabilityRule::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'user_id' => $salon['staff']->id,
        'weekday' => Weekday::Wednesday,
    ]);

    actingAsTenant($salon['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('diary.open_today', true));
});
