<?php

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

function bookingPageProps(string $slug, array $query = []): array
{
    $url = route('public.booking.show', $slug).($query === [] ? '' : '?'.http_build_query($query));

    $html = test()->get($url)->assertOk()->getContent();

    preg_match('/id="booking-props"[^>]*>(.*?)<\/script>/s', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '{}', ENT_QUOTES), true) ?? [];
}

it('tells a tenant with no services and no hours that booking is not set up yet', function () {
    $tenant = Tenant::factory()->create(['slug' => 'not-set-up-yet', 'type' => 'groomer']);

    $props = bookingPageProps($tenant->slug);

    expect($props['suggestion']['state'])->toBe('setup_incomplete')
        ->and($props['suggestion']['primary'])->toBeNull()
        ->and($props['suggestion']['setup_note'])->toContain('has not finished setting up online booking')
        ->and($props['suggestion']['setup_note'])->toContain('salon');
});

it('treats a tenant with services but nobody with hours as setup-incomplete', function () {
    $tenant = Tenant::factory()->create(['slug' => 'services-no-hours']);
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
    $service->staff()->attach($staff->id);

    expect(bookingPageProps($tenant->slug)['suggestion']['state'])->toBe('setup_incomplete');
});

it('treats a tenant whose only services are inactive as setup-incomplete', function () {
    $tenant = Tenant::factory()->create(['slug' => 'draft-services-only']);
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create(['tenant_id' => $tenant->id, 'is_active' => false]);
    $service->staff()->attach($staff->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    expect(bookingPageProps($tenant->slug)['suggestion']['state'])->toBe('setup_incomplete');
});

it('keeps the fully-booked waitlist state for a configured salon with no free slots', function () {
    $salon = aSalon(['tenant' => ['slug' => 'genuinely-full']]);
    $tenant = $salon['tenant'];

    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

    $cursor = CarbonImmutable::parse('2026-03-03 09:00:00', 'Europe/London');

    while ($cursor->lte(CarbonImmutable::parse('2026-04-15', 'Europe/London'))) {
        if ($cursor->isoWeekday() === 2) {
            for ($hour = 9; $hour < 17; $hour++) {
                $starts = $cursor->setTime($hour, 0);

                Booking::factory()->create([
                    'tenant_id' => $tenant->id,
                    'staff_id' => $salon['staff']->id,
                    'service_id' => $salon['service']->id,
                    'customer_id' => $customer->id,
                    'starts_at' => $starts->utc(),
                    'ends_at' => $starts->addHour()->utc(),
                    'status' => BookingStatus::Confirmed,
                ]);
            }
        }

        $cursor = $cursor->addDay();
    }

    $props = bookingPageProps($tenant->slug);

    expect($props['suggestion']['state'])->toBe('fully_booked')
        ->and($props['suggestion']['primary'])->toBeNull()
        ->and($props['suggestion']['setup_note'])->toBeNull();
});

it('proposes an appointment for a salon that opens on one weekday only', function () {
    $salon = aSalon(['tenant' => ['slug' => 'tuesdays-only']]);

    $props = bookingPageProps($salon['tenant']->slug);

    expect($props['suggestion']['state'])->toBe('proposal')
        ->and($props['suggestion']['primary'])->not->toBeNull()
        ->and($props['suggestion']['primary']['date'])->toBe('2026-03-03');
});

it('never runs the availability engine for a setup-incomplete tenant', function () {
    $tenant = Tenant::factory()->create(['slug' => 'no-engine-call']);

    $touchedTimeOff = false;

    DB::listen(function ($query) use (&$touchedTimeOff) {
        if (str_contains($query->sql, 'time_off')) {
            $touchedTimeOff = true;
        }
    });

    expect(bookingPageProps($tenant->slug)['suggestion']['state'])->toBe('setup_incomplete')
        ->and($touchedTimeOff)->toBeFalse();
});

it('does run the availability engine once a salon is configured', function () {
    $salon = aSalon(['tenant' => ['slug' => 'engine-runs']]);

    $touchedTimeOff = false;

    DB::listen(function ($query) use (&$touchedTimeOff) {
        if (str_contains($query->sql, 'time_off')) {
            $touchedTimeOff = true;
        }
    });

    expect(bookingPageProps($salon['tenant']->slug)['suggestion']['state'])->toBe('proposal')
        ->and($touchedTimeOff)->toBeTrue();
});

it('keeps the booking page dark until onboarding finishes', function () {
    $this->post(route('register'), [
        'name' => 'Maya Reed',
        'email' => 'maya@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('onboarding.show'));

    $tenant = Tenant::query()->where('email', 'maya@example.com')->sole();

    $this->patch(route('onboarding.basics'), [
        'name' => 'Bramble & Co',
        'slug' => 'bramble-co',
        'type' => 'groomer',
        'currency' => 'GBP',
        'hours' => collect(range(1, 7))->map(fn (int $day) => [
            'weekday' => $day,
            'open' => $day <= 5,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ])->all(),
    ])->assertSessionHasNoErrors();

    expect($tenant->fresh()->name)->toBe('Bramble & Co')
        ->and($tenant->fresh()->onboarding_completed_at)->toBeNull()
        ->and($tenant->fresh()->booking_page_live)->toBeFalse();

    $this->get(route('public.booking.show', $tenant->fresh()->slug))->assertNotFound();
});

it('shows the owner previewing an unfinished page the setup state, not a full diary', function () {
    $this->post(route('register'), [
        'name' => 'Maya Reed',
        'email' => 'maya@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $tenant = Tenant::query()->where('email', 'maya@example.com')->sole();

    $this->patch(route('onboarding.basics'), [
        'name' => 'Bramble & Co',
        'slug' => 'bramble-co',
        'type' => 'groomer',
        'currency' => 'GBP',
        'hours' => collect(range(1, 7))->map(fn (int $day) => [
            'weekday' => $day,
            'open' => $day <= 5,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ])->all(),
    ])->assertSessionHasNoErrors();

    $tenant = $tenant->fresh();

    $html = $this->get(route('booking.preview', $tenant->preview_token))->assertOk()->getContent();

    preg_match('/id="booking-props"[^>]*>(.*?)<\/script>/s', $html, $matches);
    $props = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);

    expect($props['tenant']['name'])->toBe('Bramble & Co')
        ->and($props['suggestion']['state'])->toBe('setup_incomplete');
});

it('treats a service nobody is assigned to as setup-incomplete, in its own words', function () {
    $salon = aSalon(['tenant' => ['slug' => 'nobody-does-it', 'type' => 'groomer'], 'service' => ['name' => 'Full groom']]);
    $salon['service']->staff()->detach();

    $touchedTimeOff = false;

    DB::listen(function ($query) use (&$touchedTimeOff) {
        if (str_contains($query->sql, 'time_off')) {
            $touchedTimeOff = true;
        }
    });

    $suggestion = bookingPageProps($salon['tenant']->slug)['suggestion'];

    expect($suggestion['state'])->toBe('setup_incomplete')
        ->and($suggestion['setup_reason'])->toBe('no_staff_for_service')
        ->and($suggestion['primary'])->toBeNull()
        ->and($suggestion['setup_heading'])->toBe('Full groom is not bookable online yet')
        ->and($suggestion['setup_note'])->toBe('Nobody at this salon is set up to take full groom online yet, so there are no times to show.')
        ->and($suggestion['setup_note'])->not->toContain('has not finished setting up online booking')
        ->and($touchedTimeOff)->toBeFalse();
});

it('says something different for an unstaffed service than for an unfinished business', function () {
    $salon = aSalon(['tenant' => ['slug' => 'two-sentences'], 'service' => ['name' => 'Full groom']]);
    $salon['service']->staff()->detach();

    $perService = bookingPageProps($salon['tenant']->slug)['suggestion'];

    $bare = Tenant::factory()->create(['slug' => 'nothing-at-all']);
    $perTenant = bookingPageProps($bare->slug)['suggestion'];

    expect($perService['state'])->toBe($perTenant['state'])
        ->and($perService['setup_reason'])->not->toBe($perTenant['setup_reason'])
        ->and($perService['setup_note'])->not->toBe($perTenant['setup_note'])
        ->and($perService['setup_heading'])->not->toBe($perTenant['setup_heading']);
});

it('leaves a properly staffed service on the normal flow', function () {
    $salon = aSalon(['tenant' => ['slug' => 'staffed-service']]);

    $suggestion = bookingPageProps($salon['tenant']->slug, ['service' => $salon['service']->id])['suggestion'];

    expect($suggestion['state'])->toBe('proposal')
        ->and($suggestion['setup_reason'])->toBeNull()
        ->and($suggestion['setup_note'])->toBeNull()
        ->and($suggestion['setup_heading'])->toBeNull()
        ->and($suggestion['primary'])->not->toBeNull();
});

it('keeps a staffed and an unstaffed service on the same tenant independent', function () {
    $salon = aSalon(['tenant' => ['slug' => 'one-of-each'], 'service' => ['name' => 'Full groom', 'sort_order' => 1]]);

    $orphan = Service::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Hand strip',
        'is_active' => true,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'sort_order' => 2,
    ]);

    $staffed = bookingPageProps($salon['tenant']->slug, ['service' => $salon['service']->id])['suggestion'];
    $unstaffed = bookingPageProps($salon['tenant']->slug, ['service' => $orphan->id])['suggestion'];

    expect($staffed['state'])->toBe('proposal')
        ->and($staffed['setup_reason'])->toBeNull()
        ->and($staffed['primary'])->not->toBeNull();

    expect($unstaffed['state'])->toBe('setup_incomplete')
        ->and($unstaffed['setup_reason'])->toBe('no_staff_for_service')
        ->and($unstaffed['setup_heading'])->toBe('Hand strip is not bookable online yet')
        ->and($unstaffed['setup_note'])->toContain('hand strip');
});

it('names no_service as the tenant-level reason when there is no service', function () {
    $bare = Tenant::factory()->create(['slug' => 'reason-no-service']);

    expect(bookingPageProps($bare->slug)['suggestion']['setup_reason'])->toBe('no_service');
});

it('names no_staff as the tenant-level reason when nobody has hours', function () {
    $salon = aSalon(['tenant' => ['slug' => 'reason-no-staff']]);

    AvailabilityRule::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->delete();

    expect(bookingPageProps($salon['tenant']->slug)['suggestion']['setup_reason'])->toBe('no_staff');
});
