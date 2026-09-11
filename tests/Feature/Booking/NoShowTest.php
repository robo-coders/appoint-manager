<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-03 08:00:00', 'Europe/London'));
});

/** @return array{tenant: Tenant, staff: User, service: Service, owner: User, customer: Customer} */
function aNoShowSalon(): array
{
    $salon = aSalon();

    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);

    $customer = new Customer;
    $customer->forceFill([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Priya Raman',
        'email' => 'priya@example.com',
        'phone' => '+447700900123',
    ])->save();

    return [...$salon, 'owner' => $owner, 'customer' => $customer];
}

function aNoShowBooking(array $salon, string $when = '2026-03-10 09:00:00'): Booking
{
    return app(BookingService::class)->create(
        $salon['tenant'],
        $salon['service'],
        $salon['staff'],
        $salon['customer'],
        CarbonImmutable::parse($when, 'Europe/London')->utc(),
        BookingSource::Online,
    );
}

it('marks a past confirmed appointment as a no show', function () {
    $salon = aNoShowSalon();
    $booking = aNoShowBooking($salon);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));

    $this->actingAs($salon['owner'])
        ->post(route('bookings.no-show', $booking))
        ->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('records who marked it', function () {
    $salon = aNoShowSalon();
    $booking = aNoShowBooking($salon);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));

    $this->actingAs($salon['owner'])->post(route('bookings.no-show', $booking));

    $log = AuditLog::query()->where('action', 'booking.no_show')->sole();

    expect($log->actor_id)->toBe($salon['owner']->id)
        ->and($log->target_tenant_id)->toBe($salon['tenant']->id)
        ->and($log->meta['booking_id'])->toBe($booking->id);
});

it('treats a second press as a no-op rather than an error', function () {
    $salon = aNoShowSalon();
    $booking = aNoShowBooking($salon);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));

    app(BookingService::class)->markNoShow($booking);
    app(BookingService::class)->markNoShow($booking->fresh());

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('refuses an appointment that has not happened yet', function () {
    $salon = aNoShowSalon();
    $booking = aNoShowBooking($salon, '2026-03-17 09:00:00');

    $this->actingAs($salon['owner'])
        ->from(route('bookings.show', $booking))
        ->post(route('bookings.no-show', $booking))
        ->assertSessionHasErrors('status');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('refuses a cancelled appointment', function () {
    $salon = aNoShowSalon();
    $booking = aNoShowBooking($salon);
    app(BookingService::class)->cancel($booking, 'admin');

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));

    $this->actingAs($salon['owner'])
        ->from(route('bookings.show', $booking))
        ->post(route('bookings.no-show', $booking))
        ->assertSessionHasErrors('status');

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('refuses a pending request that was never accepted', function () {
    $salon = aNoShowSalon();
    $booking = aNoShowBooking($salon);
    $booking->forceFill([
        'status' => BookingStatus::Pending,
        'deposit_status' => DepositStatus::Required,
    ])->save();

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));

    $this->actingAs($salon['owner'])
        ->from(route('bookings.show', $booking))
        ->post(route('bookings.no-show', $booking))
        ->assertSessionHasErrors('status');

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

it('refuses an appointment already marked as done', function () {
    $salon = aNoShowSalon();
    $booking = aNoShowBooking($salon);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));
    app(BookingService::class)->complete($booking);

    $this->actingAs($salon['owner'])
        ->from(route('bookings.show', $booking))
        ->post(route('bookings.no-show', $booking))
        ->assertSessionHasErrors('status');

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
});

it('refuses an operator from another salon', function () {
    $salon = aNoShowSalon();
    $booking = aNoShowBooking($salon);

    app(TenantContext::class)->clear();
    $intruder = User::factory()->create(['tenant_id' => aSalon()['tenant']->id]);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:30:00', 'Europe/London'));

    $this->actingAs($intruder)
        ->post(route('bookings.no-show', $booking))
        ->assertNotFound();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('moves the dashboard no-show rate off zero once a booking is marked', function () {
    $salon = aNoShowSalon();

    $missed = aNoShowBooking($salon, '2026-03-10 09:00:00');
    $kept = aNoShowBooking($salon, '2026-03-10 11:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-03-11 10:30:00', 'Europe/London'));

    actingAsTenant($salon['owner'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('band.no_shows.value', '—'));

    $this->actingAs($salon['owner'])->post(route('bookings.no-show', $missed))->assertSessionHasNoErrors();
    $this->actingAs($salon['owner'])->post(route('bookings.complete', $kept))->assertSessionHasNoErrors();

    actingAsTenant($salon['owner'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('band.no_shows.value', '50.0%'));
});
