<?php

use App\Enums\BookingStatus;
use App\Models\Customer;
use App\Models\Service;
use App\Models\WaitlistEntry;
use Inertia\Testing\AssertableInertia;

it('sends the freed slot the waitlist is being asked to fill', function () {
    $salon = aDiarySalon();
    $booking = aDiaryBooking($salon, '2026-08-19 15:00', '2026-08-19 16:30', [
        'status' => BookingStatus::Cancelled,
    ]);

    actingAsTenant($salon['user'])
        ->get(route('waitlist.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('freed.booking_id', $booking->id)
            ->where('freed.minutes', 90)
            ->where('freed.time', '15:00')
            ->where('freed.staff', $salon['staff']->name)
            ->where('freed.customer', $salon['customer']->name));
});

it('sends no freed slot when the hour has been rebooked', function () {
    $salon = aDiarySalon();
    aDiaryBooking($salon, '2026-08-19 15:00', '2026-08-19 16:30', [
        'status' => BookingStatus::Cancelled,
    ]);
    aDiaryBooking($salon, '2026-08-19 15:00', '2026-08-19 16:30');

    actingAsTenant($salon['user'])
        ->get(route('waitlist.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('freed', null));
});

it('prefers the slot the dashboard linked to', function () {
    $salon = aDiarySalon();
    aDiaryBooking($salon, '2026-08-19 15:00', '2026-08-19 16:30', ['status' => BookingStatus::Cancelled]);
    $later = aDiaryBooking($salon, '2026-08-19 17:00', '2026-08-19 18:00', ['status' => BookingStatus::Cancelled]);

    actingAsTenant($salon['user'])
        ->get(route('waitlist.index', ['slot' => $later->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('freed.booking_id', $later->id));
});

it('names the pet and the service under the customer, and no phone number', function () {
    $salon = aDiarySalon();
    $service = Service::factory()->create(['tenant_id' => $salon['tenant']->id, 'name' => 'Full groom']);
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'phone' => '+447700900123']);

    WaitlistEntry::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'is_active' => true,
    ]);

    actingAsTenant($salon['user'])
        ->get(route('waitlist.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries.0.subject_name')
            ->where('entries.0.service_name', 'Full groom'));
});

it('refuses to offer a slot that is not freed', function () {
    $salon = aDiarySalon();
    $live = aDiaryBooking($salon, '2026-08-19 15:00', '2026-08-19 16:30');

    actingAsTenant($salon['user'])
        ->post(route('waitlist.offer', $live))
        ->assertNotFound();
});

it('offers a freed slot to the people waiting on it', function () {
    $salon = aDiarySalon();
    $booking = aDiaryBooking($salon, '2026-08-19 15:00', '2026-08-19 16:30', [
        'status' => BookingStatus::Cancelled,
    ]);

    actingAsTenant($salon['user'])
        ->post(route('waitlist.offer', $booking))
        ->assertRedirect(route('waitlist.index'))
        ->assertSessionHas('toast');
});
