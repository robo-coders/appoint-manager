<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Stripe\StripeGateway;
use App\Support\TenantContext;

/**
 * The Payments screen on an installation with no Stripe credentials.
 *
 * This is the screen a salon owner opens *to add* payments, and three of its
 * four actions used to be a 500 with a stack trace. `connect`, `refresh` and
 * `returned` type-hinted `StripeGateway`, whose binding refuses to resolve
 * without credentials (AUDIT C1). Method injection meant the container asked
 * that question while building the action's arguments — before a line of the
 * action ran — so there was nowhere to catch it and nothing to say.
 *
 * `refresh` and `returned` are the two URLs Stripe itself sends the owner back
 * to, which means the stack trace landed on someone who had just left the
 * product to do what we asked and come back.
 *
 * The suite runs under `testing`, where the fake gateway is bound and always
 * resolves, so these tests have to leave that environment to see the bug at
 * all — the same reason it was found in a browser and not here.
 *
 * Its own helper rather than `UnconfiguredPaymentsTest`'s: a helper declared in
 * a test file only exists once that file is loaded, and under `--parallel`
 * these two land in different workers. See the note in `tests/Pest.php`.
 */
function onAnInstallationWithoutStripe(): void
{
    app()['env'] = 'local';

    config([
        'services.stripe.key' => null,
        'services.stripe.secret' => null,
        'services.stripe.webhook_secret' => null,
    ]);

    // The singleton was already built under `testing`. Drop it, so the next
    // resolution asks the binding the question this test is about.
    app()->forgetInstance(StripeGateway::class);
}

/** An owner of a salon that has finished onboarding and never connected Stripe. */
function anOwnerWithNoStripeAccount(): User
{
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'onboarding_completed_at' => now(),
        'stripe_account_id' => null,
        'stripe_onboarding_complete' => false,
    ]);

    app(TenantContext::class)->set($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(TenantContext::class)->clear();

    return $user;
}

/*
 * Leaving `testing` also leaves the CSRF bypass, so the POST carries a real
 * token — which is what the screen's own button does. Disabling the middleware
 * would have been shorter and would have quietly changed what this covers.
 */
function postingConnectAs(User $user)
{
    return test()
        ->actingAs($user)
        ->withSession(['_token' => 'a-real-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'a-real-csrf-token')
        ->post(route('settings.payments.connect'));
}

it('shows the connect screen rather than a 500', function () {
    $owner = anOwnerWithNoStripeAccount();

    onAnInstallationWithoutStripe();

    $this->actingAs($owner)
        ->get(route('settings.payments.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Payments')
            ->where('status', 'not_started')
            ->where('reachable', false));
});

/*
 * The three that name the bug. Each was a 500 before the fix.
 */
it('does not fail at container resolution when connecting', function () {
    $owner = anOwnerWithNoStripeAccount();

    onAnInstallationWithoutStripe();

    postingConnectAs($owner)->assertRedirect(route('settings.payments.show'));
});

it('does not fail at container resolution on the refresh URL Stripe sends back to', function () {
    $owner = anOwnerWithNoStripeAccount();

    onAnInstallationWithoutStripe();

    $this->actingAs($owner)
        ->get(route('settings.payments.refresh'))
        ->assertRedirect(route('settings.payments.show'));
});

it('does not fail at container resolution on the return URL Stripe sends back to', function () {
    $owner = anOwnerWithNoStripeAccount();
    $owner->tenant->forceFill(['stripe_account_id' => 'acct_half_done'])->save();

    onAnInstallationWithoutStripe();

    $this->actingAs($owner)
        ->get(route('settings.payments.return'))
        ->assertRedirect(route('settings.payments.show'));
});

/*
 * And it is a sentence, not a silent bounce. An owner who clicks Connect and
 * lands back where they started with nothing on screen has been told less than
 * the stack trace told them.
 */
it('says why, in a sentence the owner can act on', function () {
    $owner = anOwnerWithNoStripeAccount();

    onAnInstallationWithoutStripe();

    postingConnectAs($owner)->assertSessionHasErrors('stripe');

    $message = session('errors')->get('stripe')[0];

    expect($message)
        ->toContain('cannot be reached')
        ->toContain('Bookings still work')
        // Not the name of an environment variable she has never seen.
        ->not->toContain('STRIPE_SECRET');
});

it('does not offer a button whose only outcome is an error', function () {
    $owner = anOwnerWithNoStripeAccount();

    onAnInstallationWithoutStripe();

    $this->actingAs($owner)
        ->get(route('settings.payments.show'))
        ->assertInertia(fn ($page) => $page->where('reachable', false));
});

/*
|--------------------------------------------------------------------------
| C1 is unchanged
|--------------------------------------------------------------------------
|
| The fix moves *where* the refusal is asked for. It must not move whether the
| refusal happens.
|
*/

it('still refuses to hand out a gateway with no credentials', function () {
    onAnInstallationWithoutStripe();

    expect(fn () => app(StripeGateway::class))
        ->toThrow(RuntimeException::class, 'STRIPE_SECRET');
});

/*
 * The other direction: with credentials present, the screen offers the button.
 * Without this, "reachable is false" would pass against a constant.
 */
it('offers the button on an installation that can reach Stripe', function () {
    $owner = anOwnerWithNoStripeAccount();

    $this->actingAs($owner)
        ->get(route('settings.payments.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('reachable', true));
});
