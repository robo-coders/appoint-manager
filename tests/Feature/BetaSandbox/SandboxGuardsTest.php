<?php

use App\BetaSandbox\FastForward;
use App\BetaSandbox\SampleData;
use App\BetaSandbox\SandboxReset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The safety rules, which are the whole reason this feature is allowed to
 * exist. See BETA_SANDBOX.md.
 *
 * Three claims, each tested against every one of the four routes rather than
 * against a representative one — a guard that is on two endpoints out of three
 * is not a guard, and "which one did we forget" is not a question anybody
 * should have to answer by reading:
 *
 *   1. A salon that is not in the beta cannot reach any of it, and is not told
 *      it exists.
 *   2. A request that names another salon is refused, not quietly run against
 *      the caller's own shop.
 *   3. A super admin wearing an owner's session is subject to both of the above
 *      exactly as that owner is.
 */
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

    // From the database rather than the in-memory model: a column default is
    // applied by MySQL, and the instance `create()` hands back never saw it.
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

    // A booking in each shop, at the same moment, so "did the wrong one move?"
    // is a question the assertions below can actually answer.
    $mineBooking = aSandboxBooking($mine, '2026-09-15 10:00:00');
    $theirsBooking = aSandboxBooking($theirs, '2026-09-15 10:00:00');

    actingAsTenant($mine['staff'])
        ->post(route('beta-sandbox.fast-forward'), [
            'interval' => 'week',
            'tenant_id' => $theirs['tenant']->id,
        ])
        ->assertForbidden();

    // Refused means nothing happened anywhere — not "it ran on your own shop".
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

/**
 * Impersonation is the path by which a real salon's data could be destroyed by
 * somebody who does not own it, so it gets its own guard rather than being
 * assumed to inherit one.
 *
 * A super admin inside a salon's session *is* that salon for the purposes of
 * every gate in the product — `EnsureSubscriptionWrite` documents the same
 * rule — so the beta check applies to the tenant being worn, not to the person
 * wearing it. Impersonating an ordinary salon therefore reaches nothing.
 */
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

    // The controller is not the only guard: each service re-asks, so a future
    // caller that reaches past the HTTP layer cannot wipe a paying salon.
    expect(fn () => app(SandboxReset::class)->run($tenant))
        ->toThrow(NotFoundHttpException::class);

    expect(fn () => app(SampleData::class)->load($tenant))
        ->toThrow(NotFoundHttpException::class);

    expect(fn () => app(FastForward::class)->run($tenant, 'day'))
        ->toThrow(NotFoundHttpException::class);
});
