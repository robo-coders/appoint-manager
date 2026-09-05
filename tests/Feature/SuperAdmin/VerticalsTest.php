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

/*
|--------------------------------------------------------------------------
| The definition columns, edit and delete
|--------------------------------------------------------------------------
|
| `store()` used to write `subject_fields => []` and `default_services => []`
| over whatever was submitted, and there was no `update()` or `destroy()` at
| all. These are the tests for the three.
|
*/

/**
 * @return array<string, mixed>
 */
function aVerticalPayload(array $overrides = []): array
{
    return [
        'key' => 'barber',
        'label' => 'Barber',
        'subject_singular' => 'client',
        'subject_plural' => 'clients',
        'customer_singular' => 'client',
        'appointment_singular' => 'cut',
        'subject_fields' => [
            ['key' => 'style', 'label' => 'Usual style', 'type' => 'text', 'required' => true, 'options' => []],
            ['key' => 'length', 'label' => 'Length', 'type' => 'select', 'required' => false, 'options' => ['short', 'long']],
        ],
        'default_services' => [
            [
                'name' => 'Dry cut',
                'duration_minutes' => 30,
                'price' => 1800,
                'deposit_amount' => 500,
                'rebook_interval' => ['value' => 4, 'unit' => 'weeks'],
            ],
        ],
        ...$overrides,
    ];
}

it('persists submitted subject fields and default services', function () {
    $this->actingAs(aVerticalsAdmin())
        ->post(route('super-admin.verticals.store'), aVerticalPayload())
        ->assertRedirect(route('super-admin.verticals'))
        ->assertSessionHasNoErrors();

    $vertical = Vertical::query()->where('key', 'barber')->firstOrFail();

    expect($vertical->subject_fields)->toHaveCount(2)
        ->and($vertical->subject_fields[1]['options'])->toBe(['short', 'long'])
        ->and($vertical->default_services)->toEqual([[
            'name' => 'Dry cut',
            'duration_minutes' => 30,
            'price' => 1800,
            'deposit_amount' => 500,
            'rebook_interval' => ['value' => 4, 'unit' => 'weeks'],
        ]])
        ->and($vertical->appointment_singular)->toBe('cut');
});

it('sends the whole definition to the screen so the edit form can open pre-filled', function () {
    Vertical::query()->create(aVerticalPayload());

    $this->actingAs(aVerticalsAdmin())
        ->get(route('super-admin.verticals'))
        ->assertInertia(fn ($page) => $page
            ->where('verticals.0.key', 'barber')
            ->has('verticals.0.subject_fields', 2)
            ->has('verticals.0.default_services', 1)
            ->where('verticals.0.appointment_singular', 'cut')
            ->where('verticals.0.tenants_count', 0));
});

it('counts the tenants on each vertical', function () {
    Tenant::factory()->count(2)->create(['type' => 'groomer']);
    Tenant::factory()->create(['type' => 'barber']);

    $this->actingAs(aVerticalsAdmin())
        ->get(route('super-admin.verticals'))
        ->assertInertia(fn ($page) => $page->where('verticals.0.tenants_count', 2));
});

it('updates a vertical, definition columns included', function () {
    $vertical = Vertical::query()->create(aVerticalPayload());

    $this->actingAs(aVerticalsAdmin())
        ->patch(route('super-admin.verticals.update', $vertical), [
            'label' => 'Barbering',
            'subject_singular' => 'client',
            'subject_plural' => 'clients',
            'customer_singular' => 'client',
            'appointment_singular' => 'cut',
            'subject_fields' => [
                ['key' => 'style', 'label' => 'Usual style', 'type' => 'textarea', 'required' => false, 'options' => []],
            ],
            'default_services' => [],
        ])
        ->assertRedirect(route('super-admin.verticals'))
        ->assertSessionHasNoErrors();

    $vertical->refresh();

    expect($vertical->label)->toBe('Barbering')
        ->and($vertical->subject_fields)->toEqual([
            ['key' => 'style', 'label' => 'Usual style', 'type' => 'textarea', 'required' => false, 'options' => []],
        ])
        ->and($vertical->default_services)->toBe([]);
});

it('refuses to change a key, because tenants store it as their type', function () {
    $vertical = Vertical::query()->create(aVerticalPayload());

    $this->actingAs(aVerticalsAdmin())
        ->patch(route('super-admin.verticals.update', $vertical), [
            'key' => 'hairdresser',
            'label' => 'Barber',
            'subject_singular' => 'client',
            'subject_plural' => 'clients',
        ])
        ->assertSessionHasErrors('key');

    expect($vertical->refresh()->key)->toBe('barber');
});

it('deletes a vertical nobody is using', function () {
    $vertical = Vertical::query()->create(aVerticalPayload());

    $this->actingAs(aVerticalsAdmin())
        ->delete(route('super-admin.verticals.destroy', $vertical))
        ->assertRedirect(route('super-admin.verticals'));

    expect(Vertical::query()->where('key', 'barber')->exists())->toBeFalse();
});

it('refuses to delete a vertical a live tenant is set up as', function () {
    $vertical = Vertical::query()->create(aVerticalPayload());
    Tenant::factory()->create(['type' => 'barber']);

    $this->actingAs(aVerticalsAdmin())
        ->from(route('super-admin.verticals'))
        ->delete(route('super-admin.verticals.destroy', $vertical))
        ->assertRedirect(route('super-admin.verticals'))
        ->assertSessionHas('toast', fn (string $toast) => str_contains($toast, 'One salon is set up as Barber'));

    expect(Vertical::query()->where('key', 'barber')->exists())->toBeTrue();
});

it('lets a soft-deleted tenant stop blocking the delete', function () {
    $vertical = Vertical::query()->create(aVerticalPayload());
    $tenant = Tenant::factory()->create(['type' => 'barber']);
    $tenant->delete();

    $this->actingAs(aVerticalsAdmin())
        ->delete(route('super-admin.verticals.destroy', $vertical))
        ->assertRedirect(route('super-admin.verticals'));

    expect(Vertical::query()->where('key', 'barber')->exists())->toBeFalse();
});

it('rejects a choice field with no choices', function () {
    $this->actingAs(aVerticalsAdmin())
        ->post(route('super-admin.verticals.store'), aVerticalPayload([
            'subject_fields' => [
                ['key' => 'length', 'label' => 'Length', 'type' => 'select', 'required' => true, 'options' => []],
            ],
        ]))
        ->assertSessionHasErrors('subject_fields.0.options');
});

it('rejects two subject fields sharing a key', function () {
    $this->actingAs(aVerticalsAdmin())
        ->post(route('super-admin.verticals.store'), aVerticalPayload([
            'subject_fields' => [
                ['key' => 'style', 'label' => 'Style', 'type' => 'text', 'required' => true, 'options' => []],
                ['key' => 'style', 'label' => 'Style again', 'type' => 'text', 'required' => false, 'options' => []],
            ],
        ]))
        ->assertSessionHasErrors('subject_fields.1.key');
});

it('rejects a default service whose deposit is more than its price', function () {
    $this->actingAs(aVerticalsAdmin())
        ->post(route('super-admin.verticals.store'), aVerticalPayload([
            'default_services' => [
                ['name' => 'Dry cut', 'duration_minutes' => 30, 'price' => 1000, 'deposit_amount' => 2000],
            ],
        ]))
        ->assertSessionHasErrors('default_services.0.deposit_amount');
});

it('drops a repeater row that was added and abandoned', function () {
    $this->actingAs(aVerticalsAdmin())
        ->post(route('super-admin.verticals.store'), aVerticalPayload([
            'subject_fields' => [
                ['key' => 'style', 'label' => 'Style', 'type' => 'text', 'required' => true, 'options' => []],
                ['key' => '', 'label' => '', 'type' => 'text', 'required' => false, 'options' => []],
            ],
            'default_services' => [],
        ]))
        ->assertRedirect(route('super-admin.verticals'))
        ->assertSessionHasNoErrors();

    expect(Vertical::query()->where('key', 'barber')->firstOrFail()->subject_fields)->toHaveCount(1);
});

it('keeps a non-super-admin out of update and destroy', function () {
    $vertical = Vertical::query()->create(aVerticalPayload());
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_super_admin' => false]);

    $this->actingAs($user)->patch(route('super-admin.verticals.update', $vertical))->assertForbidden();
    $this->actingAs($user)->delete(route('super-admin.verticals.destroy', $vertical))->assertForbidden();
});
