<?php

use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

it('renders the public booking page and availability for a tenant slug', function () {
    $tenant = Tenant::factory()->create([
        'name' => 'Willow Street Grooming',
        'slug' => 'willow-street-grooming',
        'timezone' => 'Europe/London',
    ]);
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true, 'duration_minutes' => 60]);
    $service->staff()->attach($staff->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    $this->get(route('public.booking.show', $tenant->slug))
        ->assertOk()
        ->assertSee('Willow Street Grooming', false)
        ->assertSee('LocalBusiness', false)
        ->assertDontSee('Dashboard', false);

    $this->getJson(route('public.booking.availability', [
        'tenant_slug' => $tenant->slug,
        'service' => $service->id,
        'from' => '2026-03-10',
        'to' => '2026-03-10',
    ]))
        ->assertOk()
        ->assertJsonPath('days.2026-03-10.0.starts_at_local', '09:00');
});

it('returns empty arrays for closed days across the public 14-day window', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'fourteen-day-salon',
        'timezone' => 'Europe/London',
    ]);
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true, 'duration_minutes' => 60]);
    $service->staff()->attach($staff->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    $from = '2026-03-01';
    $to = '2026-03-14';

    $days = $this->getJson(route('public.booking.availability', [
        'tenant_slug' => $tenant->slug,
        'service' => $service->id,
        'from' => $from,
        'to' => $to,
    ]))
        ->assertOk()
        ->json('days');

    expect($days)->toHaveCount(14)
        ->and($days['2026-03-01'])->toBe([])
        ->and($days['2026-03-11'])->toBe([])
        ->and($days['2026-03-10'][0]['starts_at_local'])->toBe('09:00');
});

it('exposes a same-origin availability url on the public booking page', function () {
    $tenant = Tenant::factory()->create(['slug' => 'relative-urls']);

    $html = $this->get(route('public.booking.show', $tenant->slug))->assertOk()->getContent();

    preg_match('/id="booking-props">(?<json>.*?)<\/script>/s', $html, $matches);

    expect($matches['json'] ?? '')->not->toBeEmpty();

    $props = json_decode(html_entity_decode($matches['json'], ENT_QUOTES), true);

    expect($props['urls']['availability'])
        ->toStartWith('/book/')
        ->and($props['urls']['availability'])->toEndWith('/availability')
        ->and($props['urls']['availability'])->not->toContain('http');
});

it('does not list another tenant on the public page', function () {
    $tenant = Tenant::factory()->create(['slug' => 'salon-a']);
    Tenant::factory()->create(['slug' => 'salon-b', 'name' => 'Secret Salon']);

    $this->get(route('public.booking.show', $tenant->slug))
        ->assertOk()
        ->assertDontSee('Secret Salon', false);
});
