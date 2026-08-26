<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ReturningCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

it('no longer exposes an anonymous customer lookup at all', function () {
    $salon = aSalon();
    $customer = Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Priya Raman',
        'email' => 'priya@example.com',
        'phone' => '+447700900123',
    ]);
    Subject::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'name' => 'Rufus',
    ]);

    $this->getJson('/book/'.$salon['tenant']->slug.'/customer-match?email=priya@example.com')
        ->assertNotFound();
});

it('does not name the route anywhere', function () {
    expect(Route::has('public.booking.customer-match'))->toBeFalse();
});

it('does not leak a customer through the public booking page payload', function () {
    $salon = aSalon();
    Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Priya Raman',
        'email' => 'priya@example.com',
        'phone' => '+447700900123',
    ]);

    $body = $this->get(route('public.booking.show', $salon['tenant']->slug))->assertOk()->getContent();

    expect($body)->not->toContain('Priya Raman')
        ->and($body)->not->toContain('+447700900123');
});

it('does not overwrite an existing customer name and phone from a public booking', function () {
    $salon = aSalon();
    $customer = Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Priya Raman',
        'email' => 'priya@example.com',
        'phone' => '+447700900123',
    ]);

    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(
        route('public.booking.store', $salon['tenant']->slug),
        aBookingPayload($salon['service'], $salon['staff'], $startsAt, 'priya@example.com') + ['name' => 'Mallory']
    );

    $customer->refresh();

    expect($customer->name)->toBe('Priya Raman')
        ->and($customer->phone)->toBe('+447700900123');
});

/*
 * AUDIT C10 asks for two failures, not one: unauthenticated *and* wrong-tenant.
 * The anonymous lookup is gone (above), so what is left is every URL that still
 * returns customer data — and each has to fail for both.
 *
 * `$this->get()` with no `actingAs` is the unauthenticated case; a user from
 * another salon is the wrong-tenant case. A 404 rather than a 403 is the right
 * answer for the second: whether a customer id exists is itself information.
 */
function aRivalOwner(): User
{
    $rival = Tenant::factory()->create(['slug' => 'rival-salon']);

    return User::factory()->create(['tenant_id' => $rival->id]);
}

$customerUrls = [
    'the customer list' => fn () => route('customers.index'),
    'a customer record' => fn (Customer $c) => route('customers.show', $c),
    'a customer data export' => fn (Customer $c) => route('customers.export', $c),
    'customer search' => fn () => route('search'),
];

foreach ($customerUrls as $name => $url) {
    it("refuses {$name} to somebody who is not signed in", function () use ($url) {
        $salon = aSalon();
        $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'name' => 'Priya Raman']);

        $response = $this->get($url($customer));

        expect($response->status())->not->toBe(200);
        expect($response->getContent())->not->toContain('Priya Raman');
    });

    it("refuses {$name} to a signed-in owner of a different salon", function () use ($url) {
        $salon = aSalon();
        $customer = Customer::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'name' => 'Priya Raman',
            'email' => 'priya@example.com',
        ]);

        $response = actingAsTenant(aRivalOwner())->get($url($customer));

        // The list and the search are legitimate pages for a rival owner —
        // they must simply be empty of anybody else's customers.
        expect($response->getContent())->not->toContain('Priya Raman');
        expect($response->getContent())->not->toContain('priya@example.com');
    });
}

it('deletes nothing when a rival owner asks', function () {
    $salon = aSalon();
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);

    actingAsTenant(aRivalOwner())->delete(route('customers.destroy', $customer));

    expect(Customer::withoutGlobalScopes()->whereKey($customer->id)->exists())->toBeTrue();
});

/*
 * The booking host serves every salon from one origin, so one salon's manage
 * cookie is presented to the next. A token is a capability for one booking at
 * one tenant and must not identify its holder anywhere else.
 */
it('will not let one salon manage-link identify a visitor at another salon', function () {
    $salon = aSalon();
    $other = aSalon(['tenant' => ['slug' => 'other-salon']]);

    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id, 'name' => 'Priya Raman']);
    $booking = Booking::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $customer->id,
    ]);

    $body = $this->withCookie(ReturningCustomer::COOKIE, $booking->public_token)
        ->get(route('public.booking.show', $other['tenant']->slug))
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('Priya Raman');
});
