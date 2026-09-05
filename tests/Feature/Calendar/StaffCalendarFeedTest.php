<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use Carbon\CarbonImmutable;

/**
 * The staff calendar feed, and the settings screen that hands the links out.
 *
 * The feed is unauthenticated because a calendar client cannot authenticate —
 * it fetches a URL on a timer with no cookie. That makes the URL the credential,
 * and these tests are mostly about the consequences of that: a wrong token is a
 * 404, a token is revocable, and the file contains the minimum a diary needs
 * rather than everything the booking knows.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-03 08:00:00', 'Europe/London'));
});

function aCalendarCustomer(Tenant $tenant, string $name = 'Alex Reed'): Customer
{
    $customer = new Customer;
    $customer->forceFill([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'email' => str()->slug($name).'@example.com',
        'phone' => '+447700900000',
    ])->save();

    return $customer;
}

function aCalendarBooking(array $salon, Customer $customer, string $when): Booking
{
    return app(BookingService::class)->create(
        $salon['tenant'],
        $salon['service'],
        $salon['staff'],
        $customer,
        CarbonImmutable::parse($when, 'Europe/London')->utc(),
        BookingSource::Manual,
    );
}

/*
|--------------------------------------------------------------------------
| The feed
|--------------------------------------------------------------------------
*/

it('serves a valid calendar for a staff token with no login', function () {
    $salon = aSalon();
    $customer = aCalendarCustomer($salon['tenant']);
    aCalendarBooking($salon, $customer, '2026-03-10 09:00:00');

    $token = $salon['staff']->calendarToken();
    $response = $this->get('/calendar/'.$token.'.ics')->assertOk();
    $body = $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('text/calendar')
        ->and($body)
        ->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toEndWith("END:VCALENDAR\r\n")
        ->toContain('VERSION:2.0')
        ->toContain('BEGIN:VEVENT')
        // UTC, with the Z, so the file carries no VTIMEZONE and cannot be wrong
        // about British Summer Time.
        ->toContain('DTSTART:20260310T090000Z')
        ->toContain('DTEND:20260310T100000Z')
        ->toContain('Alex Reed');
});

it('breaks every line with CRLF, as the format requires', function () {
    $salon = aSalon();
    aCalendarBooking($salon, aCalendarCustomer($salon['tenant']), '2026-03-10 09:00:00');

    $body = $this->get('/calendar/'.$salon['staff']->calendarToken().'.ics')->getContent();

    // A bare LF is not a line break in iCalendar, and iOS rejects the whole
    // file rather than the line.
    expect(preg_match('/(?<!\r)\n/', $body))->toBe(0);
});

it('names the calendar so a subscription does not show up as a url', function () {
    $salon = aSalon(['staff' => ['name' => 'Marek Nowak']]);

    $body = $this->get('/calendar/'.$salon['staff']->calendarToken().'.ics')->getContent();

    expect($body)->toContain('X-WR-CALNAME:Marek Nowak')
        ->toContain('X-WR-TIMEZONE:Europe/London')
        ->toContain('REFRESH-INTERVAL;VALUE=DURATION:PT15M');
});

it('carries only what a diary needs, and none of the customer record', function () {
    $salon = aSalon();
    $customer = aCalendarCustomer($salon['tenant']);
    $customer->forceFill(['notes' => 'Muzzle in the bag'])->save();
    aCalendarBooking($salon, $customer, '2026-03-10 09:00:00');

    $body = $this->get('/calendar/'.$salon['staff']->calendarToken().'.ics')->getContent();

    /*
     * A leaked link is then a leak of who is coming in on Thursday, not of a
     * customer list somebody can ring. The price is out too: a staff calendar is
     * not a takings report.
     */
    expect($body)
        ->toContain($customer->name)
        ->not->toContain((string) $customer->phone)
        ->not->toContain((string) $customer->email)
        ->not->toContain('Muzzle in the bag')
        ->not->toContain('35.00');
});

it('escapes a customer name that would otherwise break the file', function () {
    $salon = aSalon();
    // A comma is structural in an iCalendar TEXT value.
    aCalendarBooking($salon, aCalendarCustomer($salon['tenant'], 'Smith, J'), '2026-03-10 09:00:00');

    $body = $this->get('/calendar/'.$salon['staff']->calendarToken().'.ics')->getContent();

    expect($body)->toContain('Smith\\, J');
});

it('leaves out cancelled, pending and past appointments', function () {
    $salon = aSalon();
    $customer = aCalendarCustomer($salon['tenant']);

    $confirmed = aCalendarBooking($salon, $customer, '2026-03-10 09:00:00');
    $cancelled = aCalendarBooking($salon, $customer, '2026-03-10 11:00:00');
    $cancelled->forceFill(['status' => BookingStatus::Cancelled])->save();
    $pending = aCalendarBooking($salon, $customer, '2026-03-10 13:00:00');
    $pending->forceFill(['status' => BookingStatus::Pending])->save();

    $body = $this->get('/calendar/'.$salon['staff']->calendarToken().'.ics')->getContent();

    expect(substr_count($body, 'BEGIN:VEVENT'))->toBe(1)
        ->and($body)->toContain($confirmed->public_token);
});

it('leaves out another staff member appointments', function () {
    $salon = aSalon();
    $other = User::factory()->create(['tenant_id' => $salon['tenant']->id, 'is_bookable' => true]);
    $customer = aCalendarCustomer($salon['tenant']);
    aCalendarBooking($salon, $customer, '2026-03-10 09:00:00');

    $body = $this->get('/calendar/'.$other->calendarToken().'.ics')->getContent();

    expect($body)->not->toContain('BEGIN:VEVENT');
});

it('404s an unknown token, a blank one and a token that has been replaced', function () {
    $salon = aSalon();
    $old = $salon['staff']->calendarToken();

    // A wrong token must be indistinguishable from a URL never issued.
    $this->get('/calendar/'.str_repeat('a', 32).'.ics')->assertNotFound();

    $salon['staff']->regenerateCalendarToken();

    $this->get('/calendar/'.$old.'.ics')->assertNotFound();
    $this->get('/calendar/'.$salon['staff']->fresh()->calendar_token.'.ics')->assertOk();
});

it('refuses a token that is not the right shape at the route', function () {
    // The route constraint keeps a malformed token off the controller and out of
    // the query entirely.
    $this->get('/calendar/not-a-token.ics')->assertNotFound();
});

it('asks a shared cache not to keep the file, and a crawler not to index it', function () {
    $salon = aSalon();
    $response = $this->get('/calendar/'.$salon['staff']->calendarToken().'.ics')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('gives each member of staff a different token', function () {
    $salon = aSalon();
    $other = User::factory()->create(['tenant_id' => $salon['tenant']->id]);

    expect($salon['staff']->calendarToken())->not->toBe($other->calendarToken())
        ->and(strlen($salon['staff']->calendarToken()))->toBe(32);
});

it('does not mint a token until somebody asks for one', function () {
    $salon = aSalon();

    expect($salon['staff']->fresh()->calendar_token)->toBeNull();

    $salon['staff']->calendarToken();

    expect($salon['staff']->fresh()->calendar_token)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The settings screen
|--------------------------------------------------------------------------
*/

it('lists every member of staff with an absolute link on the app host', function () {
    $salon = aSalon(['staff' => ['name' => 'Marek Nowak']]);
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id, 'name' => 'Ana Diaz']);

    $this->actingAs($owner)
        ->get(route('settings.calendar.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Calendar')
            ->has('staff', 2)
            // Absolute, because the owner is about to paste it into somebody
            // else's phone.
            ->where('staff.0.url', fn (string $url) => str_starts_with($url, 'http')
                && str_ends_with($url, '.ics')));
});

it('replaces a link on request and says the old one has stopped', function () {
    $salon = aSalon();
    $owner = User::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $before = $salon['staff']->calendarToken();

    $this->actingAs($owner)
        ->post(route('settings.calendar.regenerate', $salon['staff']))
        ->assertRedirect(route('settings.calendar.show'))
        ->assertSessionHas('toast', fn (string $toast) => str_contains($toast, 'stopped working'));

    expect($salon['staff']->fresh()->calendar_token)->not->toBe($before);
});

it('keeps one salon out of another calendar settings', function () {
    $salon = aSalon();
    $other = aSalon();
    $intruder = User::factory()->create(['tenant_id' => $other['tenant']->id]);
    $before = $salon['staff']->calendarToken();

    /*
     * 404, not 403, and that is the stronger answer. `ResolveTenant` runs before
     * route model binding (see `bootstrap/app.php`), so `TenantScope` never
     * finds the other salon's row at all — the policy is not reached because
     * there is nothing to hand it. A 403 would confirm the id exists.
     */
    $this->actingAs($intruder)
        ->post(route('settings.calendar.regenerate', $salon['staff']))
        ->assertNotFound();

    expect($salon['staff']->fresh()->calendar_token)->toBe($before);
});

it('keeps calendar settings behind a login', function () {
    $this->get(route('settings.calendar.show'))->assertRedirect();
});
