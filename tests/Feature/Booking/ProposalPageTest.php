<?php

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ReturningCustomer;
use Carbon\CarbonImmutable;

/**
 * The proposal model, end to end.
 *
 * The page has no calendar on it. What it has is one finished appointment, the
 * phrase that justifies it, three spread alternatives and a picker behind the
 * quietest control on the page — so these assert on the props the island is
 * handed rather than on rendered markup, which is where the decisions actually
 * live.
 *
 * @return array{tenant: Tenant, staff: User, service: Service}
 */
function aBookingSalon(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-08-26 08:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create([
        'name' => 'Willow Street Grooming',
        'slug' => 'willow-street-grooming',
        'timezone' => 'Europe/London',
        'city' => 'Hebden Bridge',
        'postcode' => 'HX7 8AA',
        'settings' => ['booking' => ['min_notice_hours' => 0, 'refund_window_hours' => 48]],
    ]);

    $staff = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Ana Duarte',
        'is_bookable' => true,
        'is_active' => true,
    ]);

    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Full groom',
        'duration_minutes' => 90,
        'buffer_minutes' => 0,
        'price' => 4500,
        'deposit_amount' => 1500,
        'is_active' => true,
    ]);

    $service->staff()->attach($staff->id);

    foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $day) {
        AvailabilityRule::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $staff->id,
            'weekday' => $day,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);
    }

    return compact('tenant', 'staff', 'service');
}

/** The props the island is mounted with, dug out of the JSON script tag. */
function bookingProps(string $html): array
{
    expect($html)->toContain('id="booking-props"');
    preg_match('/id="booking-props">(.*?)<\/script>/s', $html, $matches);

    return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
}

it('proposes one appointment with a reason, and three spread alternatives', function () {
    $salon = aBookingSalon();

    $props = bookingProps(
        $this->get(route('public.booking.show', $salon['tenant']->slug))->assertOk()->getContent()
    );

    $suggestion = $props['suggestion'];

    expect($suggestion['primary'])->not->toBeNull();
    expect($suggestion['primary']['reason'])->toBe('First available');
    expect($suggestion['primary']['action_label'])->toBe('Reserve Wednesday at 09:00');
    expect($suggestion['primary']['cost_line'])->toBe('£45.00, pay on the day');
    expect($suggestion['alternatives'])->toHaveCount(3);

    // The context line leads with the reason, then says what the appointment is.
    expect($suggestion['context'])->toBe('First available · full groom · 90 min with Ana');

    // The spread rule, asserted on the payload the page actually renders.
    $buckets = collect([$suggestion['primary'], ...$suggestion['alternatives']])
        ->map(fn (array $p) => $p['date'].'|'.(((int) substr($p['time'], 0, 2)) < 12 ? 'am' : 'pm'));

    expect($buckets->unique())->toHaveCount(4);
});

it('states the deposit and the refund cut-off as a date', function () {
    $salon = aBookingSalon();
    $salon['tenant']->forceFill([
        'stripe_account_id' => 'acct_test',
        'stripe_onboarding_complete' => true,
    ])->save();

    $props = bookingProps($this->get(route('public.booking.show', $salon['tenant']->slug))->getContent());
    $primary = $props['suggestion']['primary'];

    expect($primary['cost_line'])->toBe('£45.00 total, £15.00 deposit due today');
    // 48 hours before Wednesday 26 August 09:00 is Monday 24 August, which has
    // already passed — so there is no date to show, and the page says the
    // deposit is not refundable rather than printing a date in the past.
    expect($primary['free_until'])->toBeNull();

    // The alternatives run further out, and those do have a cut-off.
    expect($props['suggestion']['alternatives'][2]['free_until'])->not->toBeNull();
});

/*
 * Recognition, and the boundary that stops it leaking. Both salons are served
 * from one hostname, so one salon's cookie is presented to the next.
 */
it('recognises a returning customer from the manage-link cookie', function () {
    $salon = aBookingSalon();
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'name' => 'Naomi Ellery']);

    $booking = Booking::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-07-21 10:00:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-07-21 11:30:00', 'Europe/London')->utc(),
        'status' => BookingStatus::Completed,
    ]);

    $props = bookingProps(
        $this->withCookie(ReturningCustomer::COOKIE, $booking->public_token)
            ->get(route('public.booking.show', $salon['tenant']->slug))
            ->assertOk()
            ->getContent()
    );

    expect($props['suggestion']['returning'])->toBeTrue();
    expect($props['suggestion']['customer_name'])->toBe('Naomi Ellery');
});

it('does not let one salon’s cookie identify a visitor at another salon', function () {
    $salon = aBookingSalon();
    $other = Tenant::factory()->create(['slug' => 'other-salon', 'timezone' => 'Europe/London']);
    $customer = Customer::factory()->create(['tenant_id' => $other->id]);

    $booking = Booking::factory()->create([
        'tenant_id' => $other->id,
        'staff_id' => User::factory()->create(['tenant_id' => $other->id])->id,
        'service_id' => Service::factory()->create(['tenant_id' => $other->id])->id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Completed,
    ]);

    $props = bookingProps(
        $this->withCookie(ReturningCustomer::COOKIE, $booking->public_token)
            ->get(route('public.booking.show', $salon['tenant']->slug))
            ->assertOk()
            ->getContent()
    );

    expect($props['suggestion']['returning'])->toBeFalse();
    expect($props['suggestion']['customer_name'])->toBeNull();
});

it('remembers the customer when they open their own manage link', function () {
    $salon = aBookingSalon();
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);

    $booking = Booking::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-09-15 11:30:00', 'Europe/London')->utc(),
        'status' => BookingStatus::Confirmed,
    ]);

    $this->get(route('booking.manage.show', $booking->public_token))
        ->assertOk()
        ->assertCookie(ReturningCustomer::COOKIE, $booking->public_token);
});

/*
 * The picker never shows an empty grid. A day with nothing left comes back with
 * every candidate start still in it, flagged.
 */
it('returns taken times as well as free ones, so the picker is never empty', function () {
    $salon = aBookingSalon();

    Booking::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => Customer::factory()->create(['tenant_id' => $salon['tenant']->id])->id,
        'starts_at' => CarbonImmutable::parse('2026-08-27 09:00:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-08-27 17:00:00', 'Europe/London')->utc(),
        'status' => BookingStatus::Confirmed,
    ]);

    $day = $this->getJson(route('public.booking.availability', [
        'tenant_slug' => $salon['tenant']->slug,
        'service' => $salon['service']->id,
        'from' => '2026-08-27',
        'to' => '2026-08-27',
    ]))->assertOk()->json('days.2026-08-27');

    expect($day)->not->toBeEmpty();
    expect(collect($day)->every(fn (array $slot) => $slot['available'] === false))->toBeTrue();
    expect($day[0]['half'])->toBe('am');
    expect(collect($day)->contains(fn (array $slot) => $slot['half'] === 'pm'))->toBeTrue();
});

it('offers the waitlist, not a picker, when there is nothing bookable at all', function () {
    $salon = aBookingSalon();
    AvailabilityRule::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->delete();

    $props = bookingProps($this->get(route('public.booking.show', $salon['tenant']->slug))->assertOk()->getContent());

    expect($props['suggestion']['primary'])->toBeNull();
    expect($props['suggestion']['alternatives'])->toBe([]);
});

it('carries the salon name and town in the title, the meta and the JSON-LD', function () {
    $salon = aBookingSalon();

    $this->get(route('public.booking.show', $salon['tenant']->slug))
        ->assertOk()
        ->assertSee('<title>Willow Street Grooming — book in Hebden Bridge, HX7 8AA</title>', false)
        ->assertSee('Book with Willow Street Grooming in Hebden Bridge, HX7 8AA', false)
        ->assertSee('"@type":"LocalBusiness"', false)
        // The whole surface is roomy: 48px controls, 44px rows, 15px fields.
        ->assertSee('data-density="roomy"', false);
});
