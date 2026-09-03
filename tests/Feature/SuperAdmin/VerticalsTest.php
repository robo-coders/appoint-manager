<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;

function aVerticalsAdmin(): User
{
    return User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);
}

it('lists existing verticals by key and label', function () {
    $this->actingAs(aVerticalsAdmin())
        ->get(route('super-admin.verticals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('SuperAdmin/Verticals')
            ->has('verticals', 1)
            ->where('verticals.0.key', 'groomer')
            ->where('verticals.0.label', 'Dog grooming'));
});

it('creates a vertical and lists it afterwards', function () {
    $this->actingAs(aVerticalsAdmin())
        ->post(route('super-admin.verticals.store'), [
            'key' => 'barber',
            'label' => 'Barber',
            'subject_singular' => 'client',
            'subject_plural' => 'clients',
        ])
        ->assertRedirect(route('super-admin.verticals'))
        ->assertSessionHasNoErrors();

    $vertical = Vertical::query()->where('key', 'barber')->first();

    expect($vertical)->not->toBeNull()
        ->and($vertical->label)->toBe('Barber')
        ->and($vertical->subject_singular)->toBe('client')
        ->and($vertical->subject_plural)->toBe('clients')
        ->and($vertical->default_services)->toBe([])
        ->and($vertical->subject_fields)->toBe([]);

    $this->get(route('super-admin.verticals'))
        ->assertInertia(fn ($page) => $page
            ->has('verticals', 2)
            ->where('verticals.0.key', 'barber')
            ->where('verticals.0.label', 'Barber'));
});

it('lowercases the key and rejects spaces or mixed characters', function () {
    $admin = aVerticalsAdmin();

    $this->actingAs($admin)
        ->post(route('super-admin.verticals.store'), [
            'key' => 'Barber',
            'label' => 'Barber',
            'subject_singular' => 'client',
            'subject_plural' => 'clients',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Vertical::query()->where('key', 'barber')->exists())->toBeTrue();

    $this->actingAs($admin)
        ->post(route('super-admin.verticals.store'), [
            'key' => 'dog grooming',
            'label' => 'Dog grooming 2',
            'subject_singular' => 'dog',
            'subject_plural' => 'dogs',
        ])
        ->assertSessionHasErrors('key');
});

it('rejects a duplicate key', function () {
    $this->actingAs(aVerticalsAdmin())
        ->post(route('super-admin.verticals.store'), [
            'key' => 'groomer',
            'label' => 'Another groomer',
            'subject_singular' => 'dog',
            'subject_plural' => 'dogs',
        ])
        ->assertSessionHasErrors('key');
});

it('keeps the console closed to a non-super-admin', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => false]);

    $this->actingAs($user)->get(route('super-admin.verticals'))->assertForbidden();
});

it('puts database verticals on the register form in label order', function () {
    Vertical::factory()->create([
        'key' => 'barber',
        'label' => 'Barber',
    ]);

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Register')
            ->where('businessTypes.0.value', 'barber')
            ->where('businessTypes.0.label', 'Barber')
            ->where('businessTypes.1.value', 'groomer')
            ->where('businessTypes.1.label', 'Dog grooming'));
});

it('falls back to the groomer row when a tenant type has no matching vertical', function () {
    $tenant = Tenant::factory()->create(['type' => 'unknown']);
    $groomer = Vertical::query()->where('key', 'groomer')->firstOrFail();

    expect($tenant->vertical()['label'])->toBe($groomer->label)
        ->and($tenant->vertical()['default_services'])->toEqual($groomer->default_services)
        ->and($tenant->vertical()['subject_fields'])->toEqual($groomer->subject_fields);
});

it('seeds the groomer defaults exactly as they were in config', function () {
    $groomer = Vertical::query()->where('key', 'groomer')->firstOrFail();

    expect($groomer->default_services)->toEqual([
        ['name' => 'Full groom — small dog', 'duration_minutes' => 60, 'price' => 3500, 'deposit_amount' => 1000, 'rebook_interval' => ['value' => 6, 'unit' => 'weeks']],
        ['name' => 'Full groom — medium dog', 'duration_minutes' => 90, 'price' => 4500, 'deposit_amount' => 1000, 'rebook_interval' => ['value' => 6, 'unit' => 'weeks']],
        ['name' => 'Bath and blow dry', 'duration_minutes' => 45, 'price' => 2500, 'deposit_amount' => 1000, 'rebook_interval' => ['value' => 4, 'unit' => 'weeks']],
        ['name' => 'Nail clip', 'duration_minutes' => 15, 'price' => 1000, 'deposit_amount' => 0, 'rebook_interval' => ['value' => 3, 'unit' => 'weeks']],
    ]);
});
