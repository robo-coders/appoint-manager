<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;

/**
 * The import screen's contract with its controller.
 *
 * The screen has to be able to tell four things apart that the old payload
 * could not: which importer ran, whether it was a dry run or the real thing,
 * how many rows survived, and which ones did not. `import_preview` was a flat
 * list of rows and none of those four facts were in it.
 */
function anImportingOwner(): User
{
    $tenant = Tenant::factory()->create();

    return User::factory()->create(['tenant_id' => $tenant->id]);
}

it('answers a dry run with counts, the kind, and every failure', function () {
    $user = anImportingOwner();

    $csv = implode("\n", [
        'name,email,phone,subjects',
        'Naomi Ellery,naomi@example.com,07700900000,Bramble',
        'No Email,,07700900001,Suki',
        'Dele Okonjo,dele@example.com,07700900002,Otto',
    ]);

    actingAsTenant($user)
        ->post(route('imports.customers'), ['csv' => $csv, 'commit' => false])
        ->assertRedirect();

    $result = session('import_result');

    expect($result['kind'])->toBe('customers');
    expect($result['committed'])->toBeFalse();
    expect($result['ok'])->toBe(2);
    expect($result['failed'])->toBe(1);

    // Failures first, and all of them: a hundred rows of "ok" is not something
    // anybody reads, a hundred rows of "wrong" is.
    expect($result['rows'][0]['ok'])->toBeFalse();
    expect($result['rows'][0]['row'])->toBe(3);

    // And nothing was written.
    expect(Customer::withoutGlobalScopes()->count())->toBe(0);
});

it('marks a committed run as committed, and writes the rows', function () {
    $user = anImportingOwner();

    $csv = "name,email,phone,subjects\nNaomi Ellery,naomi@example.com,07700900000,Bramble";

    actingAsTenant($user)
        ->post(route('imports.customers'), ['csv' => $csv, 'commit' => true])
        ->assertRedirect();

    expect(session('import_result')['committed'])->toBeTrue();
    expect(Customer::withoutGlobalScopes()->where('email', 'naomi@example.com')->exists())->toBeTrue();
});

it('tells the screen which columns each importer reads', function () {
    $user = anImportingOwner();

    actingAsTenant($user)
        ->get(route('imports.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Imports/Index')
            ->where('columns.customers', ['name', 'email', 'phone', 'subjects'])
            ->where('columns.bookings', ['customer_email', 'service_name', 'staff_email', 'starts_at', 'subject_name']));
});
