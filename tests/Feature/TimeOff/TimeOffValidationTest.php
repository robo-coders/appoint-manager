<?php

use App\Models\TimeOff;
use App\Models\User;

it('rejects time off that does not end after it starts', function () {
    $owner = User::factory()->create();

    actingAsTenant($owner)
        ->post(route('time-off.store'), [
            'user_id' => $owner->id,
            'starts_on' => '2026-08-22',
            'ends_on' => '2026-08-22',
            'start_time' => '17:00',
            'end_time' => '09:00',
            'is_all_day' => false,
        ])
        ->assertSessionHasErrors('ends_on');

    expect(TimeOff::query()->count())->toBe(0);
});

it('stores all-day time off as utc bounds in the tenant timezone', function () {
    $owner = User::factory()->create();

    actingAsTenant($owner)
        ->post(route('time-off.store'), [
            'user_id' => $owner->id,
            'starts_on' => '2026-08-22',
            'ends_on' => '2026-08-22',
            'is_all_day' => true,
            'reason' => 'Holiday',
        ])
        ->assertRedirect(route('time-off.index'))
        ->assertSessionHasNoErrors();

    $entry = TimeOff::query()->first();

    expect($entry->is_all_day)->toBeTrue()
        ->and($entry->ends_at->gt($entry->starts_at))->toBeTrue();
});
