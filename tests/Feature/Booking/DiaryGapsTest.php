<?php

use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;

/**
 * The diary cannot draw open time as space without knowing where the day begins
 * and ends for each person, so the controller sends the working windows. These
 * cover the three things that shape them: the rules themselves, time off cut
 * out of them, and a day nobody works.
 */
it('sends each person their working windows for the day', function () {
    $salon = aDiarySalon();

    AvailabilityRule::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'user_id' => $salon['staff']->id,
        'weekday' => Weekday::Wednesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where("working.{$salon['staff']->id}", [['start' => '09:00', 'end' => '17:00']])
            ->where('is_today', true)
            ->where('now', '13:00'));
});

it('cuts time off out of the working windows', function () {
    $salon = aDiarySalon();

    AvailabilityRule::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'user_id' => $salon['staff']->id,
        'weekday' => Weekday::Wednesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    TimeOff::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'user_id' => $salon['staff']->id,
        'starts_at' => CarbonImmutable::parse('2026-08-19 12:00:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-08-19 13:00:00', 'Europe/London')->utc(),
    ]);

    // The lunch hour is not open time, so it is not drawn as a gap.
    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where("working.{$salon['staff']->id}", [
            ['start' => '09:00', 'end' => '12:00'],
            ['start' => '13:00', 'end' => '17:00'],
        ]));
});

it('gives somebody who does not work that day no windows at all', function () {
    $salon = aDiarySalon();

    // Tuesdays only. The day being drawn is a Wednesday.
    AvailabilityRule::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'user_id' => $salon['staff']->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where("working.{$salon['staff']->id}", []));
});

/*
 * The week view is an agenda, not a seven-by-four grid, so it has no gaps to
 * draw and does not pay for the queries that would find them.
 */
it('works out no windows for the week view', function () {
    $salon = aDiarySalon();

    actingAsTenant($salon['user'])
        ->get(route('diary.index', ['date' => '2026-08-19', 'view' => 'week']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('working', [])->where('view', 'week'));
});
