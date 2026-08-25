<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\Availability\AvailabilityEngine;
use Carbon\CarbonImmutable;

function salon(array $overrides = []): array
{
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'settings' => [
            'booking' => [
                'slot_granularity_minutes' => 15,
                'min_notice_hours' => 2,
                'horizon_days' => 60,
            ],
        ],
        ...($overrides['tenant'] ?? []),
    ]);

    $staff = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_bookable' => true,
        'is_active' => true,
    ]);

    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'duration_minutes' => $overrides['duration'] ?? 60,
        'buffer_minutes' => $overrides['buffer'] ?? 0,
        'is_active' => true,
    ]);

    $service->staff()->attach($staff->id);

    return compact('tenant', 'staff', 'service');
}

function openHours(User $staff, Weekday $weekday, string $start = '09:00:00', string $end = '17:00:00'): AvailabilityRule
{
    return AvailabilityRule::factory()->create([
        'tenant_id' => $staff->tenant_id,
        'user_id' => $staff->id,
        'weekday' => $weekday,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

function dayRange(string $localDate, string $timezone = 'Europe/London'): array
{
    $from = CarbonImmutable::parse($localDate.' 00:00:00', $timezone)->utc();
    $to = CarbonImmutable::parse($localDate.' 00:00:00', $timezone)->addDay()->utc();

    return [$from, $to];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

it('returns the correct count and first/last slot for a simple day', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = salon();
    openHours($staff, Weekday::Tuesday);

    [$from, $to] = dayRange('2026-03-10');
    $slots = app(AvailabilityEngine::class)->slotsFor($tenant, $service, $from, $to);

    $tz = 'Europe/London';

    expect($slots)->toHaveCount(29)
        ->and($slots->first()?->startsAt->timezone($tz)->format('H:i'))->toBe('09:00')
        ->and($slots->last()?->startsAt->timezone($tz)->format('H:i'))->toBe('16:00')
        ->and($slots->first()?->staffIds)->toBe([$staff->id]);
});

it('removes slots around a mid-day booking including buffers', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = salon(['buffer' => 15]);
    openHours($staff, Weekday::Tuesday);

    $bookingStart = CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/London')->utc();

    Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $staff->id,
        'service_id' => $service->id,
        'customer_id' => Customer::factory()->create(['tenant_id' => $tenant->id])->id,
        'starts_at' => $bookingStart,
        'ends_at' => $bookingStart->addHour(),
        'status' => BookingStatus::Confirmed,
        'source' => BookingSource::Manual,
    ]);

    [$from, $to] = dayRange('2026-03-10');
    $slots = app(AvailabilityEngine::class)->slotsFor($tenant, $service, $from, $to);
    $times = [];

    foreach ($slots as $slot) {
        $times[] = $slot->startsAt->timezone('Europe/London')->format('H:i');
    }

    expect($times)->toContain('10:45')
        ->and($times)->not->toContain('11:00')
        ->and($times)->not->toContain('13:00')
        ->and($times)->toContain('13:15')
        ->and($slots)->toHaveCount(20);
});

it('removes slots covered by time off', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = salon();
    openHours($staff, Weekday::Tuesday);

    TimeOff::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'starts_at' => CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-03-10 13:00:00', 'Europe/London')->utc(),
        'is_all_day' => false,
    ]);

    [$from, $to] = dayRange('2026-03-10');
    $slots = app(AvailabilityEngine::class)->slotsFor($tenant, $service, $from, $to);
    $times = [];

    foreach ($slots as $slot) {
        $times[] = $slot->startsAt->timezone('Europe/London')->format('H:i');
    }

    expect($times)->toContain('11:00')
        ->and($times)->not->toContain('11:15')
        ->and($times)->not->toContain('12:45')
        ->and($times)->toContain('13:00')
        ->and($slots)->toHaveCount(22);
});

it('unions slots across two staff with different hours', function () {
    ['tenant' => $tenant, 'staff' => $alice, 'service' => $service] = salon();
    $bob = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_bookable' => true,
        'is_active' => true,
    ]);
    $service->staff()->attach($bob->id);

    openHours($alice, Weekday::Tuesday, '09:00:00', '13:00:00');
    openHours($bob, Weekday::Tuesday, '13:00:00', '17:00:00');

    [$from, $to] = dayRange('2026-03-10');
    $slots = app(AvailabilityEngine::class)->slotsFor($tenant, $service, $from, $to);

    $noon = CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/London')->utc();
    $one = CarbonImmutable::parse('2026-03-10 13:00:00', 'Europe/London')->utc();
    $nine = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();
    $four = CarbonImmutable::parse('2026-03-10 16:00:00', 'Europe/London')->utc();

    expect($slots->staffIdsFor($nine))->toBe([$alice->id])
        ->and($slots->staffIdsFor($noon))->toBe([$alice->id])
        ->and($slots->staffIdsFor($one))->toBe([$bob->id])
        ->and($slots->staffIdsFor($four))->toBe([$bob->id])
        ->and($slots)->toHaveCount(26);
});

it('handles the UK spring-forward gap without duplicate or missing slots', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = salon(['duration' => 15]);
    openHours($staff, Weekday::Sunday, '00:00:00', '04:00:00');

    [$from, $to] = dayRange('2026-03-29');
    $slots = app(AvailabilityEngine::class)->slotsFor($tenant, $service, $from, $to);

    $timestamps = [];
    $localHours = [];

    foreach ($slots as $slot) {
        $timestamps[] = $slot->startsAt->utc()->getTimestamp();
        $localHours[] = $slot->startsAt->timezone('Europe/London')->format('H:i');
    }

    expect($timestamps)->toHaveCount(count(array_unique($timestamps)))
        ->and($slots)->toHaveCount(12)
        ->and($localHours)->toContain('00:00')
        ->and($localHours)->toContain('00:45')
        ->and($localHours)->not->toContain('01:00')
        ->and($localHours)->not->toContain('01:30')
        ->and($localHours)->toContain('02:00')
        ->and($localHours)->toContain('03:45');
});

it('does not duplicate slots across the UK autumn-back repeated hour', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00:00', 'Europe/London'));

    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = salon(['duration' => 15]);
    openHours($staff, Weekday::Sunday, '00:00:00', '04:00:00');

    [$from, $to] = dayRange('2026-10-25');
    $slots = app(AvailabilityEngine::class)->slotsFor($tenant, $service, $from, $to);

    $timestamps = [];
    $local = [];

    foreach ($slots as $slot) {
        $timestamps[] = $slot->startsAt->utc()->getTimestamp();
        $local[] = $slot->startsAt->timezone('Europe/London')->format('H:i');
    }

    expect($timestamps)->toHaveCount(count(array_unique($timestamps)))
        ->and($slots)->toHaveCount(20)
        ->and($local)->toContain('00:00')
        ->and($local)->toContain('03:45');
});

it('still means 09:00–17:00 local on both UK clock-change dates', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = salon();
    openHours($staff, Weekday::Sunday);

    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
    [$from, $to] = dayRange('2026-03-29');
    $spring = app(AvailabilityEngine::class)->slotsFor($tenant, $service, $from, $to);

    expect($spring)->toHaveCount(29)
        ->and($spring->first()?->startsAt->timezone('Europe/London')->format('Y-m-d H:i'))->toBe('2026-03-29 09:00')
        ->and($spring->last()?->startsAt->timezone('Europe/London')->format('Y-m-d H:i'))->toBe('2026-03-29 16:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00:00', 'Europe/London'));
    [$from, $to] = dayRange('2026-10-25');
    $autumn = app(AvailabilityEngine::class)->slotsFor($tenant, $service, $from, $to);

    expect($autumn)->toHaveCount(29)
        ->and($autumn->first()?->startsAt->timezone('Europe/London')->format('Y-m-d H:i'))->toBe('2026-10-25 09:00')
        ->and($autumn->last()?->startsAt->timezone('Europe/London')->format('Y-m-d H:i'))->toBe('2026-10-25 16:00');
});

it('excludes slots inside the minimum notice period', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = salon();
    openHours($staff, Weekday::Tuesday);

    $this->travelTo(CarbonImmutable::parse('2026-03-10 08:30:00', 'Europe/London'));

    [$from, $to] = dayRange('2026-03-10');
    $slots = app(AvailabilityEngine::class)->slotsFor($tenant, $service, $from, $to);
    $times = [];

    foreach ($slots as $slot) {
        $times[] = $slot->startsAt->timezone('Europe/London')->format('H:i');
    }

    expect($times)->not->toContain('09:00')
        ->and($times)->not->toContain('10:15')
        ->and($times)->toContain('10:30')
        ->and($slots->first()?->startsAt->timezone('Europe/London')->format('H:i'))->toBe('10:30');
});

it('returns zero slots when the service is longer than any window', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = salon(['duration' => 180]);
    openHours($staff, Weekday::Tuesday, '09:00:00', '11:00:00');

    [$from, $to] = dayRange('2026-03-10');
    $slots = app(AvailabilityEngine::class)->slotsFor($tenant, $service, $from, $to);

    expect($slots)->toHaveCount(0);
});
