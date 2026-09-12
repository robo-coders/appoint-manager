<?php

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function aSalonAtStepOne(): array
{
    $tenant = Tenant::factory()->onboardingIncomplete()->create([
        'timezone' => 'Europe/London',
        'booking_page_live' => false,
    ]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'is_bookable' => true,
        'is_active' => true,
    ]);

    return compact('tenant', 'owner');
}

/** @return list<array{weekday: int, open: bool, start_time: string, end_time: string}> */
function anOnboardingWeekOpen(int ...$weekdays): array
{
    return collect(range(1, 7))
        ->map(fn (int $day) => [
            'weekday' => $day,
            'open' => in_array($day, $weekdays, true),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ])
        ->all();
}

function runSetupSteps(User $owner, string $slug): void
{
    actingAsTenant($owner)->patch(route('onboarding.basics'), [
        'name' => 'Paws & Whiskers Grooming',
        'slug' => $slug,
        'type' => 'groomer',
        'hours' => anOnboardingWeekOpen(1, 2, 3, 4, 5),
    ])->assertSessionHasNoErrors();

    test()->patch(route('onboarding.business'), [
        'timezone' => 'Europe/London',
    ])->assertSessionHasNoErrors();

    test()->patch(route('onboarding.services'), [
        'name' => 'Full groom',
        'duration_minutes' => 60,
        'price' => 4200,
        'deposit_amount' => 0,
    ])->assertSessionHasNoErrors();
}

function propsOnTheBookingPage(string $slug): array
{
    $html = test()->get(route('public.booking.show', $slug))->assertOk()->getContent();

    preg_match('/id="booking-props"[^>]*>(.*?)<\/script>/s', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '{}', ENT_QUOTES), true) ?? [];
}

/** @return list<int> */
function serviceIdsLinkedTo(User $staff): array
{
    return DB::table('service_user')
        ->where('user_id', $staff->id)
        ->pluck('service_id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

beforeEach(fn () => test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'Europe/London')));

it('links a solo owner to the service created during setup', function () {
    $salon = aSalonAtStepOne();

    runSetupSteps($salon['owner'], 'paws-and-whiskers');

    $service = Service::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect(serviceIdsLinkedTo($salon['owner']))->toBe([$service->id]);
});

it('offers real times on the public page the moment a solo owner finishes setup', function () {
    $salon = aSalonAtStepOne();

    runSetupSteps($salon['owner'], 'paws-and-whiskers');

    test()->post(route('onboarding.complete'), ['slug' => 'paws-and-whiskers'])
        ->assertRedirect(route('diary.index'));

    $props = propsOnTheBookingPage('paws-and-whiskers');

    expect($props['suggestion']['state'])->toBe('proposal')
        ->and($props['suggestion']['setup_reason'])->toBeNull()
        ->and($props['suggestion']['primary'])->not->toBeNull();

    $service = Service::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    $days = test()->getJson(route('public.booking.availability', [
        'tenant_slug' => 'paws-and-whiskers',
        'service' => $service->id,
        'from' => '2026-09-07',
        'to' => '2026-09-07',
    ]))->assertOk()->json('days.2026-09-07');

    expect(collect($days)->where('available', true))->not->toBeEmpty();
});

it('books the optional first appointment for a solo owner', function () {
    $salon = aSalonAtStepOne();

    runSetupSteps($salon['owner'], 'paws-and-whiskers');

    $service = Service::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    test()->post(route('onboarding.complete'), [
        'slug' => 'paws-and-whiskers',
        'first_booking' => [
            'customer_name' => 'Naomi Ellery',
            'customer_email' => 'naomi@example.com',
            'service_id' => $service->id,
            'staff_id' => $salon['owner']->id,
            'starts_at' => '2026-09-07T09:00',
        ],
    ])->assertSessionHasNoErrors()->assertRedirect(route('diary.index', ['date' => '2026-09-07']));

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe(1);
});
