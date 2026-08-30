<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Exceptions\SlotUnavailableException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use Carbon\CarbonImmutable;
use Tests\Support\Concurrent;

function bookableSalon(): array
{
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
    ]);
    $staff = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_bookable' => true,
        'is_active' => true,
    ]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'is_active' => true,
        'price' => 3500,
        'deposit_amount' => 1000,
    ]);
    $service->staff()->attach($staff->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    return compact('tenant', 'staff', 'service');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

afterEach(function () {
    Concurrent::afterEach();
});

it('creates an unconnected online booking as confirmed with no deposit', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = bookableSalon();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $booking = app(BookingService::class)->create(
        $tenant,
        $service,
        $staff,
        $customer,
        $startsAt,
        BookingSource::Online,
    );

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->deposit_status->value)->toBe('none')
        ->and($booking->source)->toBe(BookingSource::Online)
        ->and($booking->public_token)->not->toBeEmpty()
        ->and($booking->stripe_payment_intent_id)->toBeNull();
});

/*
 * These two used to be sequential. They now fork two PHP processes with their
 * own PDO connections and release them from a barrier. That is what found the
 * deadlock: `lockForUpdate()` on an empty bookings window gap-locks the index,
 * both transactions then INSERT into the same gap, and InnoDB kills one with
 * SQLSTATE 40001. The database ends with one row — the lock works — but the
 * loser sees a 500, not a 409. Left failing on purpose. See DECISIONS.md.
 */
it('lets exactly one of two concurrent transactions take the same slot', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = bookableSalon();
    $first = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $second = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $job = [
        'type' => 'book',
        'tenant_id' => $tenant->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $startsAt->toIso8601String(),
    ];

    $results = Concurrent::withoutWrappingTransaction(fn () => Concurrent::run([
        [...$job, 'customer_id' => $first->id],
        [...$job, 'customer_id' => $second->id],
    ]));

    $wins = array_values(array_filter($results, fn (array $r) => ($r['ok'] ?? false) === true));
    $losses = array_values(array_filter($results, fn (array $r) => ($r['error'] ?? null) === SlotUnavailableException::class));
    $bookings = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();

    expect($wins)->toHaveCount(1, 'workers: '.json_encode($results))
        ->and($losses)->toHaveCount(1, 'workers: '.json_encode($results))
        ->and($bookings)->toBe(1);
});

it('returns 409 to exactly one of two concurrent public requests for the same slot', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = bookableSalon();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $base = [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $startsAt->toIso8601String(),
        'phone' => '07700900000',
        'subject_name' => 'Willow',
        'subject_attributes' => [
            'breed' => 'Labrador',
            'size' => 'medium',
        ],
    ];

    $uri = route('public.booking.store', $tenant->slug, absolute: false);

    $results = Concurrent::withoutWrappingTransaction(fn () => Concurrent::run([
        ['type' => 'http', 'method' => 'POST', 'uri' => $uri, 'payload' => [...$base, 'name' => 'Alex Reed', 'email' => 'alex@example.com']],
        ['type' => 'http', 'method' => 'POST', 'uri' => $uri, 'payload' => [...$base, 'name' => 'Jamie Cole', 'email' => 'other@example.com']],
    ]));

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([201, 409], 'workers: '.json_encode($results))
        ->and(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});
