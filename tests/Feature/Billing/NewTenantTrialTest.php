<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DemoTenantSeeder;

/**
 * A brand-new salon can write to its own diary.
 *
 * `tenants.subscription_status` defaults to `trial`, `trial_ends_at` defaults to
 * NULL, and `Tenant::hasAdminWriteAccess()` reads the *date*. Any tenant that
 * reached the admin with a NULL trial had no write access at all: the whole app
 * rendered behind "The diary is read-only until billing is up to date", on the
 * owner's first login, before they had done anything wrong.
 *
 * The registration controller has always set the date, so the paying-customer
 * path was never broken — that much is asserted below rather than assumed. What
 * was broken is every *other* door into the tenants table, and there are
 * several: `DemoTenantSeeder`, the tinker `firstOrCreate` in
 * `scripts/e2e-setup.sh`, anything a support script would do. The fix is a
 * `creating` hook on the model, so the door does not matter.
 */

/*
|--------------------------------------------------------------------------
| The real flow, end to end
|--------------------------------------------------------------------------
|
| No factory anywhere in this test. It registers over HTTP, walks the four
| onboarding steps over HTTP, lands on the dashboard and writes a row — which
| is the only way to prove the banner is not there, because a blocked write is
| a redirect with a toast rather than a status code.
|
*/
it('registers, onboards, reaches the dashboard and can write', function () {
    $this->post(route('register'), [
        'business_name' => 'Willow Street Grooming',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya@willowstreet.example',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('onboarding.show'));

    $tenant = Tenant::query()->where('email', 'maya@willowstreet.example')->firstOrFail();

    expect($tenant->trial_ends_at)->not->toBeNull()
        ->and($tenant->onTrial())->toBeTrue()
        ->and($tenant->isReadOnly())->toBeFalse();

    $owner = User::withoutGlobalScopes()->where('email', 'maya@willowstreet.example')->firstOrFail();
    $this->assertAuthenticatedAs($owner);

    $this->patch(route('onboarding.business'), [
        'timezone' => 'Europe/London',
        'phone' => '020 7946 0123',
        'address_line_1' => '12 Willow Street',
        'city' => 'London',
        'postcode' => 'E8 3AA',
    ])->assertRedirect(route('onboarding.show', ['step' => 'services']));

    $this->patch(route('onboarding.services'), [
        'services' => [
            ['name' => 'Full groom', 'duration_minutes' => 90, 'price' => 3500, 'deposit_amount' => 1000],
        ],
    ])->assertRedirect(route('onboarding.show', ['step' => 'staff']));

    $this->patch(route('onboarding.staff'), [
        'staff' => [['name' => 'Jordan Blake', 'email' => 'jordan@willowstreet.example']],
    ])->assertRedirect(route('onboarding.show', ['step' => 'hours']));

    $this->patch(route('onboarding.hours'), [
        'rules' => [
            ['user_id' => $owner->id, 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
        ],
    ])->assertRedirect(route('diary.index'));

    $this->get(route('dashboard'))->assertOk();

    /*
     * The write. `EnsureSubscriptionWrite` does not 403 a blocked write on a
     * browser request — it sends you back with a toast — so a status assertion
     * would pass against the bug. The row is the assertion.
     */
    $response = $this->post(route('waitlist.store'), [
        'name' => 'Naomi Ellery',
        'email' => 'naomi@example.com',
        'phone' => '07700900000',
        'service_id' => $tenant->services()->firstOrFail()->id,
    ]);

    $response->assertRedirect(route('waitlist.index'));
    $response->assertSessionMissing('toast.0');

    expect(Customer::withoutGlobalScopes()->where('email', 'naomi@example.com')->exists())
        ->toBeTrue('a brand-new salon could not write to its own waitlist');
});

/*
 * And the read-only banner is genuinely absent rather than merely un-asserted.
 * `HandleInertiaRequests` shares the tenant's billing state with every page;
 * this is the flag the layout draws the banner from.
 */
it('does not show a new salon the read-only billing banner', function () {
    $this->post(route('register'), [
        'business_name' => 'Willow Street Grooming',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya@willowstreet.example',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $tenant = Tenant::query()->where('email', 'maya@willowstreet.example')->firstOrFail();
    $tenant->forceFill(['onboarding_completed_at' => now()])->save();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenant.read_only', false));
});

/*
|--------------------------------------------------------------------------
| Every other door into the tenants table
|--------------------------------------------------------------------------
*/

/*
 * This is where the bug actually lived. A tenant made with the columns the
 * migration defines and nothing else — which is what a seeder, a tinker
 * `firstOrCreate` or a support script produces — used to be born read-only.
 */
it('gives a trial to a tenant created with nothing but a name', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Made By Hand',
        'slug' => 'made-by-hand',
        'type' => 'groomer',
        'timezone' => 'Europe/London',
        'currency' => 'GBP',
    ]);

    // Re-read, because `subscription_status` is a *column* default: the row has
    // it, the instance that just wrote the row does not.
    expect($tenant->trial_ends_at)->not->toBeNull()
        ->and($tenant->fresh()->subscription_status)->toBe('trial')
        ->and($tenant->fresh()->isReadOnly())->toBeFalse();
});

it('gives the demo seeder tenant a writable diary out of the box', function () {
    (new DemoTenantSeeder)->run();
    app(TenantContext::class)->clear();

    $tenant = Tenant::query()->where('slug', 'willow-street-grooming')->firstOrFail();

    expect($tenant->isReadOnly())->toBeFalse();
});

/*
 * The trial is `config('billing.trial_days')` long, not a number written twice.
 * Registration and the hook have to agree, or the length silently depends on
 * which door the tenant came through.
 */
it('uses the configured trial length wherever the tenant was created', function () {
    config(['billing.trial_days' => 45]);

    $this->post(route('register'), [
        'business_name' => 'Willow Street Grooming',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya@willowstreet.example',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $registered = Tenant::query()->where('email', 'maya@willowstreet.example')->firstOrFail();
    $byHand = Tenant::query()->create([
        'name' => 'Made By Hand',
        'slug' => 'made-by-hand',
        'type' => 'groomer',
        'timezone' => 'Europe/London',
        'currency' => 'GBP',
    ]);

    expect($registered->trial_ends_at->toDateString())->toBe(now()->addDays(45)->toDateString())
        ->and($byHand->trial_ends_at->toDateString())->toBe(now()->addDays(45)->toDateString());
});

/*
 * The hook fills a gap; it never overrules a caller. Both directions matter:
 * a fixture that wants an expired trial has to be able to have one, or every
 * test of the read-only state becomes untestable.
 */
it('leaves an explicitly set trial date alone, expired or not', function () {
    $expired = Tenant::factory()->create(['trial_ends_at' => now()->subDay()]);

    expect($expired->trial_ends_at->isPast())->toBeTrue()
        ->and($expired->isReadOnly())->toBeTrue();
});

/*
 * And it does not resurrect a lapsed tenant. `hasAdminWriteAccess()` reads the
 * status first for active and paused, so a stray trial date on a subscribed
 * tenant changes nothing — but a tenant whose subscription has genuinely ended
 * must stay locked, and `DemoDataSeeder::billing()` sets that state by clearing
 * the date *after* the row exists.
 */
it('does not hand a fresh trial to a tenant whose subscription has lapsed', function () {
    $tenant = Tenant::factory()->create();
    $tenant->forceFill([
        'subscription_status' => 'past_due',
        'trial_ends_at' => now()->subDays(60),
        'dunning_started_at' => now()->subDays(30),
    ])->save();

    expect($tenant->fresh()->isReadOnly())->toBeTrue();
});
