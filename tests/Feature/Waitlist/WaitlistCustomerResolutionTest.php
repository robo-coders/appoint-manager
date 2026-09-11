<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use Tests\Support\Concurrent;

afterEach(function () {
    Concurrent::afterEach();
});

it('reuses the existing customer when an in-app waitlist join repeats their email', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();
    $existing = Customer::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Samantha Lee',
        'email' => 'sam@example.com',
        'phone' => '07700900999',
    ]);

    actingAsTenant($owner)
        ->withoutExceptionHandling()
        ->post(route('waitlist.store'), [
            'name' => 'Sam L',
            'email' => 'sam@example.com',
            'phone' => '07700900111',
            'service_id' => $salon['service']->id,
        ])
        ->assertRedirect(route('waitlist.index'));

    $entry = WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();
    $customers = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();

    expect($entry->customer_id)->toBe($existing->id)
        ->and($customers)->toHaveCount(1)
        ->and($customers->sole()->name)->toBe('Samantha Lee')
        ->and($customers->sole()->phone)->toBe('07700900999');
});

it('creates a customer when an in-app waitlist join uses an unseen email', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();

    actingAsTenant($owner)
        ->withoutExceptionHandling()
        ->post(route('waitlist.store'), [
            'name' => 'Sam L',
            'email' => 'sam@example.com',
            'phone' => '07700900111',
            'service_id' => $salon['service']->id,
        ])
        ->assertRedirect(route('waitlist.index'));

    $customer = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();
    $entry = WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect($customer->name)->toBe('Sam L')
        ->and($customer->email)->toBe('sam@example.com')
        ->and($customer->phone)->toBe('+447700900111')
        ->and($entry->customer_id)->toBe($customer->id);
});

it('does not match a customer belonging to another tenant', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();
    $other = Tenant::factory()->create();
    $stranger = Customer::factory()->create([
        'tenant_id' => $other->id,
        'name' => 'Samantha Lee',
        'email' => 'sam@example.com',
        'phone' => '07700900999',
    ]);

    actingAsTenant($owner)
        ->withoutExceptionHandling()
        ->post(route('waitlist.store'), [
            'name' => 'Sam L',
            'email' => 'sam@example.com',
            'phone' => '07700900111',
            'service_id' => $salon['service']->id,
        ])
        ->assertRedirect(route('waitlist.index'));

    $created = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();
    $entry = WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect($created->id)->not->toBe($stranger->id)
        ->and($created->name)->toBe('Sam L')
        ->and($entry->customer_id)->toBe($created->id)
        ->and($stranger->fresh()->name)->toBe('Samantha Lee')
        ->and(Customer::withoutGlobalScopes()->where('tenant_id', $other->id)->count())->toBe(1);
});

it('reuses one customer when two concurrent in-app waitlist joins share a new email', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();

    $payload = [
        'name' => 'Sam L',
        'email' => 'sam@example.com',
        'phone' => '07700900111',
        'service_id' => $salon['service']->id,
    ];

    $job = [
        'type' => 'http',
        'method' => 'POST',
        'uri' => route('waitlist.store', absolute: false),
        'user_id' => $owner->id,
        'payload' => $payload,
    ];

    $results = Concurrent::withoutWrappingTransaction(fn () => Concurrent::run([$job, $job]));

    $statuses = array_column($results, 'status');
    sort($statuses);

    $customers = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();
    $entries = WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();

    expect($statuses)->toBe([302, 302], 'workers: '.json_encode($results))
        ->and($customers)->toHaveCount(1)
        ->and($customers->sole()->email)->toBe('sam@example.com')
        ->and($entries)->toHaveCount(1)
        ->and($entries->sole()->customer_id)->toBe($customers->sole()->id);
});
