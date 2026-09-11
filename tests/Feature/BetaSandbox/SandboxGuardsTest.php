<?php

use App\BetaSandbox\FastForward;
use App\BetaSandbox\SampleData;
use App\BetaSandbox\SandboxReset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/London'));
});

/** @return list<array{0: string, 1: string}> Every sandbox endpoint, as method + route name. */
function sandboxEndpoints(): array
{
    return [
        ['get', 'beta-sandbox.show'],
        ['post', 'beta-sandbox.sample-data'],
        ['post', 'beta-sandbox.fast-forward'],
        ['post', 'beta-sandbox.reset'],
        ['post', 'beta-sandbox.jump'],
        ['post', 'beta-sandbox.no-show'],
        ['post', 'beta-sandbox.waitlist-free'],
        ['post', 'beta-sandbox.waitlist-expire'],
        ['post', 'beta-sandbox.remind'],
        ['post', 'beta-sandbox.outbox-clear'],
        ['post', 'beta-sandbox.flaky'],
    ];
}

it('hides every sandbox route from a salon that is not in the beta', function () {
    $salon = aSalon();

    expect($salon['tenant']->fresh()->is_beta)->toBeFalse();

    foreach (sandboxEndpoints() as [$method, $name]) {
        actingAsTenant($salon['staff'])
            ->{$method}(route($name), ['interval' => 'day'])
            ->assertNotFound();
    }
});

it('opens every sandbox route to a salon that is in the beta', function () {
    $salon = aBetaSalon();

    actingAsTenant($salon['staff'])->get(route('beta-sandbox.show'))->assertOk();

    actingAsTenant($salon['staff'])
        ->post(route('beta-sandbox.fast-forward'), ['interval' => 'day'])
        ->assertRedirect();

    actingAsTenant($salon['staff'])->post(route('beta-sandbox.reset'))->assertRedirect();
});

it('refuses a fast-forward that names another salon rather than running it on this one', function () {
    $mine = aBetaSalon();
    $theirs = aBetaSalon();

    $mineBooking = aSandboxBooking($mine, '2026-09-15 10:00:00');
    $theirsBooking = aSandboxBooking($theirs, '2026-09-15 10:00:00');

    actingAsTenant($mine['staff'])
        ->post(route('beta-sandbox.fast-forward'), [
            'interval' => 'week',
            'tenant_id' => $theirs['tenant']->id,
        ])
        ->assertForbidden();

    expect($mineBooking->fresh()->starts_at->toDateTimeString())
        ->toBe($mineBooking->starts_at->toDateTimeString());
    expect($theirsBooking->fresh()->starts_at->toDateTimeString())
        ->toBe($theirsBooking->starts_at->toDateTimeString());
});

it('refuses a reset that names another salon, and wipes nobody', function () {
    $mine = aBetaSalon();
    $theirs = aBetaSalon();

    aSandboxBooking($mine, '2026-09-15 10:00:00');
    aSandboxBooking($theirs, '2026-09-15 10:00:00');

    actingAsTenant($mine['staff'])
        ->post(route('beta-sandbox.reset'), ['tenant_id' => $theirs['tenant']->id])
        ->assertForbidden();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $mine['tenant']->id)->count())->toBe(1);
    expect(Booking::withoutGlobalScopes()->where('tenant_id', $theirs['tenant']->id)->count())->toBe(1);
});

it('refuses a sample-data load that names another salon', function () {
    $mine = aBetaSalon();
    $theirs = aBetaSalon();

    actingAsTenant($mine['staff'])
        ->post(route('beta-sandbox.sample-data'), ['tenant_id' => $theirs['tenant']->id])
        ->assertForbidden();

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $mine['tenant']->id)->count())->toBe(0);
    expect(Customer::withoutGlobalScopes()->where('tenant_id', $theirs['tenant']->id)->count())->toBe(0);
});

it('accepts a request that names the caller\'s own salon', function () {
    $salon = aBetaSalon();

    actingAsTenant($salon['staff'])
        ->post(route('beta-sandbox.reset'), ['tenant_id' => $salon['tenant']->id])
        ->assertRedirect();
});

it('refuses an unknown fast-forward interval instead of guessing one', function () {
    $salon = aBetaSalon();

    actingAsTenant($salon['staff'])
        ->post(route('beta-sandbox.fast-forward'), ['interval' => 'decade'])
        ->assertStatus(422);
});

it('gives a super admin impersonating an ordinary salon no way into the sandbox', function () {
    $salon = aSalon();
    $admin = User::factory()->superAdmin()->create();

    foreach (sandboxEndpoints() as [$method, $name]) {
        actingAsTenant($salon['staff'])
            ->withSession(['impersonator_id' => $admin->id])
            ->{$method}(route($name), ['interval' => 'day'])
            ->assertNotFound();
    }
});

it('still refuses cross-tenant tampering while impersonating a beta salon', function () {
    $beta = aBetaSalon();
    $real = aSalon();
    $admin = User::factory()->superAdmin()->create();

    aSandboxBooking($real, '2026-09-15 10:00:00');

    actingAsTenant($beta['staff'])
        ->withSession(['impersonator_id' => $admin->id])
        ->post(route('beta-sandbox.reset'), ['tenant_id' => $real['tenant']->id])
        ->assertForbidden();

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $real['tenant']->id)->count())->toBe(1);
});

it('never lets the services themselves act on a salon outside the beta', function () {
    $salon = aSalon();
    $tenant = $salon['tenant'];

    expect(fn () => app(SandboxReset::class)->run($tenant))
        ->toThrow(NotFoundHttpException::class);

    expect(fn () => app(SampleData::class)->load($tenant))
        ->toThrow(NotFoundHttpException::class);

    expect(fn () => app(FastForward::class)->run($tenant, 'day'))
        ->toThrow(NotFoundHttpException::class);
});
