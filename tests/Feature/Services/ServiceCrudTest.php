<?php

use App\Models\Service;
use App\Models\User;

it('creates a service with staff assignment', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->for($owner->tenant)->staff()->create();

    actingAsTenant($owner)
        ->post(route('services.store'), [
            'name' => 'Full groom',
            'duration_minutes' => 60,
            'buffer_minutes' => 10,
            'price' => 3500,
            'deposit_amount' => 1000,
            'is_active' => true,
            'staff_ids' => [$owner->id, $staff->id],
        ])
        ->assertRedirect(route('services.index'))
        ->assertSessionHasNoErrors();

    $service = Service::query()->first();

    expect($service->name)->toBe('Full groom')
        ->and($service->price->amount)->toBe(3500)
        ->and($service->staff()->pluck('users.id')->all())->toEqualCanonicalizing([$owner->id, $staff->id]);
});

it('rejects a duration that is not a multiple of five', function () {
    $owner = User::factory()->create();

    actingAsTenant($owner)
        ->post(route('services.store'), [
            'name' => 'Cut',
            'duration_minutes' => 7,
            'price' => 1000,
            'deposit_amount' => 0,
            'staff_ids' => [],
        ])
        ->assertSessionHasErrors('duration_minutes');
});

it('rejects a deposit greater than price', function () {
    $owner = User::factory()->create();

    actingAsTenant($owner)
        ->post(route('services.store'), [
            'name' => 'Cut',
            'duration_minutes' => 30,
            'price' => 1000,
            'deposit_amount' => 1500,
            'staff_ids' => [],
        ])
        ->assertSessionHasErrors('deposit_amount');
});

it('toggles, reorders and soft deletes a service', function () {
    $owner = User::factory()->create();
    $first = Service::factory()->for($owner->tenant)->create(['name' => 'A', 'sort_order' => 0]);
    $second = Service::factory()->for($owner->tenant)->create(['name' => 'B', 'sort_order' => 1]);

    actingAsTenant($owner)
        ->patch(route('services.update', $first), ['is_active' => false])
        ->assertRedirect(route('services.index'));

    expect($first->fresh()->is_active)->toBeFalse();

    $this->patch(route('services.reorder'), [
        'ids' => [$second->id, $first->id],
    ])->assertRedirect(route('services.index'));

    expect($second->fresh()->sort_order)->toBe(0)
        ->and($first->fresh()->sort_order)->toBe(1);

    $this->delete(route('services.destroy', $first))->assertRedirect(route('services.index'));

    expect(Service::query()->find($first->id))->toBeNull()
        ->and(Service::withTrashed()->find($first->id))->not->toBeNull();
});
