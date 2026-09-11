<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

function aBookingRecord(array $overrides = []): array
{
    test()->travelTo(CarbonImmutable::parse('2026-03-16 09:00:00', 'Europe/London'));

    $salon = aSalon(['staff' => ['name' => 'Marek Kowalski']]);
    $owner = $salon['staff'];
    $owner->forceFill(['role' => 'owner'])->save();
    app(TenantContext::class)->set($salon['tenant']);

    $customer = Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Tomas Marlow',
        'email' => 'tomas@example.test',
        'phone' => '+447700900108',
    ]);

    $booking = Booking::factory()->create(array_merge([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $owner->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-03-17 12:00:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-03-17 13:00:00', 'Europe/London')->utc(),
        'status' => BookingStatus::Pending,
        'deposit_status' => DepositStatus::Required,
        'price_at_booking' => 3500,
        'deposit_at_booking' => 1000,
        'source' => BookingSource::Online,
    ], $overrides));

    app(TenantContext::class)->clear();

    return [$owner, $booking, $customer, $salon['tenant']];
}

/** @return array<string, string> */
function rowsOf(array $groups, string $label): array
{
    $group = collect($groups)->firstWhere('label', $label);

    return collect($group['rows'])->pluck('value', 'key')->all();
}

it('groups the record into customer, scheduling, payment and status', function () {
    [$owner, $booking] = aBookingRecord();

    $response = actingAsTenant($owner)->get(route('bookings.show', $booking));
    $response->assertOk();

    $groups = $response->viewData('page')['props']['groups'];

    expect(collect($groups)->pluck('label')->all())->toBe(['Customer', 'Scheduling', 'Payment', 'Status']);

    expect(rowsOf($groups, 'Customer'))->toMatchArray([
        'Name' => 'Tomas Marlow',
        'Email' => 'tomas@example.test',
        'Phone' => '+447700900108',
        'History' => 'First booking',
    ]);

    expect(rowsOf($groups, 'Scheduling'))->toMatchArray([
        'Date' => 'Tuesday 17 March 2026',
        'Time' => '12:00 — 13:00',
        'Duration' => '60 min',
        'Staff' => 'Marek Kowalski',
    ]);

    expect(rowsOf($groups, 'Status'))->toMatchArray([
        'Booking' => 'Awaiting deposit',
        'Deposit' => 'Deposit due',
        'Source' => 'Booking page',
    ]);
});

it('works out what is still owed on the day rather than leaving her to', function () {
    [$owner, $booking] = aBookingRecord();

    $response = actingAsTenant($owner)->get(route('bookings.show', $booking));
    $payment = rowsOf($response->viewData('page')['props']['groups'], 'Payment');

    expect($payment)->toMatchArray([
        'Service price' => '£35.00',
        'Deposit due' => '£10.00',
        'Deposit paid' => '£0.00',
        'Balance on the day' => '£35.00',
    ]);

    $booking->forceFill([
        'status' => BookingStatus::Confirmed,
        'deposit_status' => DepositStatus::Paid,
    ])->save();

    $response = actingAsTenant($owner)->get(route('bookings.show', $booking));
    $payment = rowsOf($response->viewData('page')['props']['groups'], 'Payment');

    expect($payment)->toMatchArray([
        'Deposit paid' => '£10.00',
        'Balance on the day' => '£25.00',
    ]);
});

it('counts what this customer has done before, no-shows included', function () {
    [$owner, $booking, $customer, $tenant] = aBookingRecord();

    app(TenantContext::class)->set($tenant);

    foreach ([BookingStatus::Completed, BookingStatus::NoShow, BookingStatus::Completed] as $status) {
        Booking::factory()->create([
            'tenant_id' => $booking->tenant_id,
            'staff_id' => $booking->staff_id,
            'service_id' => $booking->service_id,
            'customer_id' => $customer->id,
            'starts_at' => CarbonImmutable::parse('2026-01-05 09:00:00', 'Europe/London')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-01-05 10:00:00', 'Europe/London')->utc(),
            'status' => $status,
            'price_at_booking' => 3500,
        ]);
    }

    app(TenantContext::class)->clear();

    $response = actingAsTenant($owner)->get(route('bookings.show', $booking));

    expect(rowsOf($response->viewData('page')['props']['groups'], 'Customer'))
        ->toMatchArray(['History' => '4 bookings · 1 no-show']);
});

it('shows what has actually been sent, and nothing when nothing has', function () {
    [$owner, $booking, $customer, $tenant] = aBookingRecord();

    $response = actingAsTenant($owner)->get(route('bookings.show', $booking));
    expect($response->viewData('page')['props']['activity'])->toBe([]);

    app(TenantContext::class)->set($tenant);

    Message::query()->create([
        'tenant_id' => $booking->tenant_id,
        'customer_id' => $customer->id,
        'booking_id' => $booking->id,
        'channel' => MessageChannel::Email,
        'type' => MessageType::BookingRequested,
        'to' => $customer->email,
        'body' => 'Request received.',
        'status' => MessageStatus::Sent,
    ]);

    Message::query()->create([
        'tenant_id' => $booking->tenant_id,
        'customer_id' => $customer->id,
        'booking_id' => $booking->id,
        'channel' => MessageChannel::Sms,
        'type' => MessageType::Reminder,
        'to' => $customer->phone,
        'body' => 'Tomorrow at 12:00.',
        'status' => MessageStatus::Failed,
    ]);

    app(TenantContext::class)->clear();

    $activity = actingAsTenant($owner)->get(route('bookings.show', $booking))->viewData('page')['props']['activity'];

    expect($activity)->toHaveCount(2);
    expect($activity[0]['text'])->toBe('Request received emailed.');
    expect($activity[1]['text'])->toBe('Reminder texted — failed to send.');
    expect($activity[0]['at'])->toBe('16 Mar, 09:00');
});

it('names a cancellation and its reason under status', function () {
    [$owner, $booking] = aBookingRecord();

    $booking->forceFill([
        'status' => BookingStatus::Cancelled,
        'cancelled_at' => CarbonImmutable::parse('2026-03-16 10:30:00', 'Europe/London')->utc(),
        'cancellation_reason' => 'admin',
    ])->save();

    $response = actingAsTenant($owner)->get(route('bookings.show', $booking));

    expect(rowsOf($response->viewData('page')['props']['groups'], 'Status'))->toMatchArray([
        'Booking' => 'Cancelled',
        'Cancelled' => '16 Mar 2026, 10:30',
        'Reason' => 'admin',
    ]);
});

it('says nothing about a deposit for a salon that does not take one', function () {
    [$owner, $booking] = aBookingRecord([
        'status' => BookingStatus::Confirmed,
        'deposit_status' => DepositStatus::None,
        'deposit_at_booking' => 0,
    ]);

    $groups = actingAsTenant($owner)
        ->get(route('bookings.show', $booking))
        ->viewData('page')['props']['groups'];

    expect(rowsOf($groups, 'Payment'))->toBe([
        'Service price' => '£35.00',
        'Due on the day' => '£35.00',
    ]);

    expect(rowsOf($groups, 'Status'))->not->toHaveKey('Deposit');
});
