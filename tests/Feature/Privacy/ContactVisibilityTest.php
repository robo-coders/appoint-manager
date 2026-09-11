<?php

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\MaskedContact;

/** @return array{tenant: Tenant, owner: User, staff: User, mine: Customer, theirs: Customer, booking: Booking} */
function aSalonWithTwoCustomers(bool $canSeeContacts = false): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'can_see_customer_contacts' => false,
    ]);

    $staff = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Staff,
        'can_see_customer_contacts' => $canSeeContacts,
    ]);

    $service = Service::factory()->create(['tenant_id' => $tenant->id]);

    $mine = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Naomi Ellery',
        'email' => 'naomi@example.com',
        'phone' => '07700900123',
    ]);

    $theirs = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Priya Raman',
        'email' => 'priya@example.com',
        'phone' => '07700900456',
    ]);

    $booking = Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $staff->id,
        'service_id' => $service->id,
        'customer_id' => $mine->id,
    ]);

    Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $owner->id,
        'service_id' => $service->id,
        'customer_id' => $theirs->id,
        'starts_at' => now()->utc()->addDays(2)->setTime(10, 0),
        'ends_at' => now()->utc()->addDays(2)->setTime(11, 0),
    ]);

    return compact('tenant', 'owner', 'staff', 'mine', 'theirs', 'booking');
}

function payloadOf($response): string
{
    return json_encode($response->viewData('page')['props'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';
}

it('hides contact fields from a staff member with the toggle off', function () {
    $salon = aSalonWithTwoCustomers(canSeeContacts: false);

    $response = actingAsTenant($salon['staff'])->get(route('customers.index'))->assertOk();
    $payload = payloadOf($response);

    expect($payload)
        ->not->toContain('priya@example.com')
        ->not->toContain('07700900456');

    $rows = collect($response->viewData('page')['props']['customers']['data']);
    $theirs = $rows->firstWhere('id', $salon['theirs']->id);

    expect($theirs['email'])->toBeNull()
        ->and($theirs['contact_hidden'])->toBeTrue()
        ->and($theirs['phone'])->toBe(MaskedContact::phone('07700900456'))
        ->and($theirs['phone'])->toEndWith('56');
});

it('still shows a staff member the contact details of their own customers', function () {
    $salon = aSalonWithTwoCustomers(canSeeContacts: false);

    $response = actingAsTenant($salon['staff'])->get(route('customers.index'))->assertOk();

    $rows = collect($response->viewData('page')['props']['customers']['data']);
    $mine = $rows->firstWhere('id', $salon['mine']->id);

    expect($mine['email'])->toBe('naomi@example.com')
        ->and($mine['phone'])->toBe('07700900123')
        ->and($mine['contact_hidden'])->toBeFalse();
});

it('hides contact on a booking assigned to somebody else, and shows it on their own', function () {
    $salon = aSalonWithTwoCustomers(canSeeContacts: false);

    $response = actingAsTenant($salon['staff'])
        ->get(route('bookings.show', $salon['booking']))
        ->assertOk();

    expect(payloadOf($response))->toContain('07700900123');

    $other = Booking::query()->where('staff_id', $salon['owner']->id)->sole();

    $response = actingAsTenant($salon['staff'])
        ->get(route('bookings.show', $other))
        ->assertOk();

    $payload = payloadOf($response);

    expect($payload)
        ->not->toContain('priya@example.com')
        ->not->toContain('07700900456')
        ->toContain(MaskedContact::NOTICE);
});

it('masks the number on the waitlist and the overdue list', function () {
    $salon = aSalonWithTwoCustomers(canSeeContacts: false);

    WaitlistEntry::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $salon['theirs']->id,
        'service_id' => Service::factory()->create(['tenant_id' => $salon['tenant']->id])->id,
    ]);

    $response = actingAsTenant($salon['staff'])->get(route('waitlist.index'))->assertOk();

    expect(payloadOf($response))->not->toContain('07700900456');

    $response = actingAsTenant($salon['staff'])->get(route('overdue.index'))->assertOk();

    expect(payloadOf($response))->not->toContain('07700900456');
});

it('will not let the search box confirm an address it would refuse to print', function () {
    $salon = aSalonWithTwoCustomers(canSeeContacts: false);

    $response = actingAsTenant($salon['staff'])
        ->getJson(route('search', ['q' => 'priya@example.com']))
        ->assertOk();

    expect($response->json('customers'))->toBe([]);

    $response = actingAsTenant($salon['staff'])
        ->get(route('customers.index', ['search' => 'priya@example.com']))
        ->assertOk();

    expect($response->viewData('page')['props']['customers']['data'])->toBe([]);
});

it('refuses the subject-access export to somebody who cannot read the details', function () {
    $salon = aSalonWithTwoCustomers(canSeeContacts: false);

    actingAsTenant($salon['staff'])
        ->get(route('customers.export', $salon['theirs']))
        ->assertForbidden();

    actingAsTenant($salon['staff'])
        ->get(route('customers.export', $salon['mine']))
        ->assertOk();
});

it('shows every contact field to a staff member with the toggle on', function () {
    $salon = aSalonWithTwoCustomers(canSeeContacts: true);

    $response = actingAsTenant($salon['staff'])->get(route('customers.index'))->assertOk();

    $rows = collect($response->viewData('page')['props']['customers']['data']);

    foreach ([$salon['mine'], $salon['theirs']] as $customer) {
        $row = $rows->firstWhere('id', $customer->id);

        expect($row['email'])->toBe($customer->email)
            ->and($row['phone'])->toBe($customer->phone)
            ->and($row['contact_hidden'])->toBeFalse();
    }

    $other = Booking::query()->where('staff_id', $salon['owner']->id)->sole();

    expect(payloadOf(actingAsTenant($salon['staff'])->get(route('bookings.show', $other))))
        ->toContain('07700900456');

    expect(actingAsTenant($salon['staff'])->getJson(route('search', ['q' => 'priya@example.com']))->json('customers'))
        ->toHaveCount(1);
});

it('shows every contact field to the owner regardless of the toggle', function () {
    $salon = aSalonWithTwoCustomers(canSeeContacts: false);

    expect($salon['owner']->can_see_customer_contacts)->toBeFalse();

    $response = actingAsTenant($salon['owner'])->get(route('customers.index'))->assertOk();
    $rows = collect($response->viewData('page')['props']['customers']['data']);

    foreach ([$salon['mine'], $salon['theirs']] as $customer) {
        $row = $rows->firstWhere('id', $customer->id);

        expect($row['email'])->toBe($customer->email)
            ->and($row['phone'])->toBe($customer->phone)
            ->and($row['contact_hidden'])->toBeFalse();
    }

    expect(payloadOf(actingAsTenant($salon['owner'])->get(route('bookings.show', $salon['booking']))))
        ->toContain('07700900123');

    actingAsTenant($salon['owner'])
        ->get(route('customers.export', $salon['theirs']))
        ->assertOk();
});

it('keeps the last two digits and nothing else', function () {
    expect(MaskedContact::phone('07700 900123'))->toEndWith('23')
        ->and(MaskedContact::phone('07700 900123'))->not->toContain('7700')
        ->and(MaskedContact::phone('+447700900123'))->toBe(MaskedContact::phone('07700900123'))
        ->and(MaskedContact::phone(null))->toBeNull()
        ->and(MaskedContact::phone('  '))->toBeNull();
});
