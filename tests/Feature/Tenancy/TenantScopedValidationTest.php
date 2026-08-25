<?php

use App\Enums\UserRole;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Rules\ExistsForTenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Validator;

it('rejects an id from another tenant', function () {
    $mine = aSalon();
    $theirs = aSalon();
    app(TenantContext::class)->set($mine['tenant']);

    $validator = Validator::make(
        ['user_id' => $theirs['staff']->id],
        ['user_id' => [ExistsForTenant::of(User::class)]],
    );

    expect($validator->passes())->toBeFalse();
});

it('accepts an id from my own tenant', function () {
    $mine = aSalon();
    app(TenantContext::class)->set($mine['tenant']);

    $validator = Validator::make(
        ['user_id' => $mine['staff']->id],
        ['user_id' => [ExistsForTenant::of(User::class)]],
    );

    expect($validator->passes())->toBeTrue();
});

it('rejects everything when there is no tenant context', function () {
    $mine = aSalon();
    app(TenantContext::class)->clear();

    $validator = Validator::make(
        ['user_id' => $mine['staff']->id],
        ['user_id' => [ExistsForTenant::of(User::class)]],
    );

    expect($validator->passes())->toBeFalse();
});

it('rejects a soft deleted record', function () {
    $mine = aSalon();
    app(TenantContext::class)->set($mine['tenant']);
    $mine['service']->delete();

    $validator = Validator::make(
        ['service_id' => $mine['service']->id],
        ['service_id' => [ExistsForTenant::of(Service::class)]],
    );

    expect($validator->passes())->toBeFalse();
});

it('will not attach another tenant staff member to a service', function () {
    $mine = aSalon(['staff' => ['role' => UserRole::Owner]]);
    $theirs = aSalon();

    actingAsTenant($mine['staff'])->post(route('services.store'), [
        'name' => 'Full groom',
        'duration_minutes' => 60,
        'price' => 3500,
        'deposit_amount' => 0,
        'staff_ids' => [$theirs['staff']->id],
    ])->assertSessionHasErrors('staff_ids.0');

    // Their own service still has them attached; the point is that nothing new was.
    expect(DB::table('service_user')->where('user_id', $theirs['staff']->id)->count())->toBe(1)
        ->and(Service::withoutGlobalScopes()->where('tenant_id', $mine['tenant']->id)->where('name', 'Full groom')->exists())
        ->toBeFalse();
});

it('will not book time off against another tenant staff member', function () {
    $mine = aSalon(['staff' => ['role' => UserRole::Owner]]);
    $theirs = aSalon();

    actingAsTenant($mine['staff'])->post(route('time-off.store'), [
        'user_id' => $theirs['staff']->id,
        'starts_on' => '2026-03-10',
        'ends_on' => '2026-03-10',
        'is_all_day' => true,
    ])->assertSessionHasErrors('user_id');

    expect(TimeOff::withoutGlobalScopes()->where('user_id', $theirs['staff']->id)->count())->toBe(0);
});

it('will not reorder another tenant services', function () {
    $mine = aSalon(['staff' => ['role' => UserRole::Owner]]);
    $theirs = aSalon();

    actingAsTenant($mine['staff'])->patch(route('services.reorder'), [
        'ids' => [$theirs['service']->id],
    ])->assertSessionHasErrors('ids.0');
});
