<?php

use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\User;

it('draws a cancelled booking that left a gap as a freed slot', function () {
    $salon = aDiarySalon();
    $freed = aDiaryBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', [
        'status' => BookingStatus::Cancelled,
        'deposit_status' => DepositStatus::Refunded,
    ]);

    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Diary/Index')
            ->has('bookings', 1)
            ->where('bookings.0.id', $freed->id)
            ->where('bookings.0.status', 'cancelled')
            ->where('bookings.0.is_freed', true)
            ->where('bookings.0.minutes', 90));
});

it('drops a cancellation whose hour has already been refilled', function () {
    $salon = aDiarySalon();
    aDiaryBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', ['status' => BookingStatus::Cancelled]);
    $replacement = aDiaryBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00');

    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings', 1)
            ->where('bookings.0.id', $replacement->id));
});

it('still calls it freed when somebody else was booked at the same time', function () {
    $salon = aDiarySalon();
    $other = User::factory()->create(['tenant_id' => $salon['tenant']->id, 'is_bookable' => true]);

    $freed = aDiaryBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', ['status' => BookingStatus::Cancelled]);
    aDiaryBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', ['staff_id' => $other->id]);

    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings', 2)
            ->where('bookings.0.id', $freed->id)
            ->where('bookings.0.is_freed', true));
});

it('shows a past cancellation, but not as a freed slot', function () {
    $salon = aDiarySalon();
    aDiaryBooking($salon, '2026-08-19 09:00:00', '2026-08-19 10:30:00', [
        'status' => BookingStatus::Cancelled,
        'deposit_status' => DepositStatus::Paid,
        'cancellation_reason' => 'Cancelled inside notice — deposit kept',
    ]);

    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings', 1)
            ->where('bookings.0.is_freed', false)
            ->where('bookings.0.deposit_status', 'paid')
            ->where('bookings.0.cancellation_reason', 'Cancelled inside notice — deposit kept'));
});

it('draws a no show that left a gap as a freed slot', function () {
    $salon = aDiarySalon();
    $missed = aDiaryBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', [
        'status' => BookingStatus::NoShow,
    ]);

    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Diary/Index')
            ->has('bookings', 1)
            ->where('bookings.0.id', $missed->id)
            ->where('bookings.0.status', 'no_show')
            ->where('bookings.0.is_freed', true)
            ->where('bookings.0.minutes', 90));
});

it('drops a no show whose hour has already been refilled', function () {
    $salon = aDiarySalon();
    aDiaryBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00', ['status' => BookingStatus::NoShow]);
    $replacement = aDiaryBooking($salon, '2026-08-19 15:30:00', '2026-08-19 17:00:00');

    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bookings', 1)
            ->where('bookings.0.id', $replacement->id));
});
