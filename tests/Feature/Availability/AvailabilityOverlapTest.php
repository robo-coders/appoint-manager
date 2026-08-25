<?php

use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\User;
use App\Support\AvailabilityOverlap;

it('rejects overlapping ranges for the same weekday', function () {
    $owner = User::factory()->create();

    actingAsTenant($owner)
        ->put(route('availability.sync', $owner), [
            'ranges' => [
                ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
                ['weekday' => 1, 'start_time' => '12:00', 'end_time' => '17:00'],
            ],
        ])
        ->assertSessionHasErrors('ranges');

    expect(AvailabilityRule::query()->count())->toBe(0);
});

it('allows adjacent ranges on the same weekday', function () {
    $owner = User::factory()->create();

    actingAsTenant($owner)
        ->put(route('availability.sync', $owner), [
            'ranges' => [
                ['weekday' => Weekday::Monday->value, 'start_time' => '09:00', 'end_time' => '12:00'],
                ['weekday' => Weekday::Monday->value, 'start_time' => '12:00', 'end_time' => '17:00'],
            ],
        ])
        ->assertRedirect(route('availability.index'))
        ->assertSessionHasNoErrors();

    expect(AvailabilityRule::query()->count())->toBe(2);
});

it('requires end time after start time', function () {
    $owner = User::factory()->create();

    actingAsTenant($owner)
        ->put(route('availability.sync', $owner), [
            'ranges' => [
                ['weekday' => 1, 'start_time' => '17:00', 'end_time' => '09:00'],
            ],
        ])
        ->assertSessionHasErrors('ranges.0.end_time');
});

it('detects overlaps in the value object', function () {
    expect(AvailabilityOverlap::rangesOverlap([
        ['start_time' => '09:00', 'end_time' => '12:00'],
        ['start_time' => '12:00', 'end_time' => '17:00'],
    ]))->toBeFalse()
        ->and(AvailabilityOverlap::rangesOverlap([
            ['start_time' => '09:00', 'end_time' => '13:00'],
            ['start_time' => '12:00', 'end_time' => '17:00'],
        ]))->toBeTrue();
});
