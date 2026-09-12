<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DemoTenantSeeder;

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

    $this->patch(route('onboarding.basics'), [
        'name' => 'Willow Street Grooming',
        'slug' => $tenant->slug,
        'type' => 'groomer',
        'hours' => collect(range(1, 7))->map(fn (int $day) => [
            'weekday' => $day,
            'open' => $day === 2,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ])->all(),
    ])->assertRedirect(route('onboarding.show', ['step' => 'business']));

    $this->patch(route('onboarding.business'), [
        'timezone' => 'Europe/London',
        'phone' => '020 7946 0123',
        'address_line_1' => '12 Willow Street',
        'city' => 'London',
        'postcode' => 'E8 3AA',
    ])->assertRedirect(route('onboarding.show', ['step' => 'services']));

    $this->patch(route('onboarding.services'), [
        'name' => 'Full groom',
        'duration_minutes' => 90,
        'price' => 3500,
        'deposit_amount' => 1000,
    ])->assertRedirect(route('onboarding.show', ['step' => 'link']));

    $this->post(route('onboarding.complete'), [
        'slug' => $tenant->slug,
    ])->assertRedirect(route('diary.index'));

    $this->get(route('dashboard'))->assertOk();

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

it('gives a trial to a tenant created with nothing but a name', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Made By Hand',
        'slug' => 'made-by-hand',
        'type' => 'groomer',
        'timezone' => 'Europe/London',
        'currency' => 'GBP',
    ]);

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

it('leaves an explicitly set trial date alone, expired or not', function () {
    $expired = Tenant::factory()->create(['trial_ends_at' => now()->subDay()]);

    expect($expired->trial_ends_at->isPast())->toBeTrue()
        ->and($expired->isReadOnly())->toBeTrue();
});

it('does not hand a fresh trial to a tenant whose subscription has lapsed', function () {
    $tenant = Tenant::factory()->create();
    $tenant->forceFill([
        'subscription_status' => 'past_due',
        'trial_ends_at' => now()->subDays(60),
        'dunning_started_at' => now()->subDays(30),
    ])->save();

    expect($tenant->fresh()->isReadOnly())->toBeTrue();
});
