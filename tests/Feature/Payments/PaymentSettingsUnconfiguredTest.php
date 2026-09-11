<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Stripe\StripeGateway;
use App\Support\TenantContext;

function onAnInstallationWithoutStripe(): void
{
    app()['env'] = 'local';

    config([
        'services.stripe.key' => null,
        'services.stripe.secret' => null,
        'services.stripe.webhook_secret' => null,
    ]);

    app()->forgetInstance(StripeGateway::class);
}

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

it('says why, in a sentence the owner can act on', function () {
    $owner = anOwnerWithNoStripeAccount();

    onAnInstallationWithoutStripe();

    postingConnectAs($owner)->assertSessionHasErrors('stripe');

    $message = session('errors')->get('stripe')[0];

    expect($message)
        ->toContain('cannot be reached')
        ->toContain('Bookings still work')
        ->not->toContain('STRIPE_SECRET');
});

it('does not offer a button whose only outcome is an error', function () {
    $owner = anOwnerWithNoStripeAccount();

    onAnInstallationWithoutStripe();

    $this->actingAs($owner)
        ->get(route('settings.payments.show'))
        ->assertInertia(fn ($page) => $page->where('reachable', false));
});

it('still refuses to hand out a gateway with no credentials', function () {
    onAnInstallationWithoutStripe();

    expect(fn () => app(StripeGateway::class))
        ->toThrow(RuntimeException::class, 'STRIPE_SECRET');
});

it('offers the button on an installation that can reach Stripe', function () {
    $owner = anOwnerWithNoStripeAccount();

    $this->actingAs($owner)
        ->get(route('settings.payments.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('reachable', true));
});
