<?php

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertOk();
});

test('registration creates a tenant and owner atomically and redirects to onboarding', function () {
    Event::fake([Registered::class]);

    $response = $this->post('/register', [
        'business_name' => 'Willow Street Grooming',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.show', absolute: false));

    $tenant = Tenant::query()->where('slug', 'willow-street-grooming')->first();
    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('Willow Street Grooming')
        ->and($tenant->type)->toBe('groomer')
        ->and($tenant->timezone)->toBe('Europe/London')
        ->and($tenant->currency)->toBe('GBP')
        ->and($tenant->onboarding_completed_at)->toBeNull();

    $owner = User::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('email', 'maya@example.com')
        ->first();
    expect($owner)->not->toBeNull()
        ->and($owner->tenant_id)->toBe($tenant->id)
        ->and($owner->name)->toBe('Maya Chen')
        ->and($owner->role)->toBe(UserRole::Owner)
        ->and($owner->is_bookable)->toBeTrue()
        ->and($owner->is_active)->toBeTrue();

    Event::assertDispatched(Registered::class);
});

test('slug receives a numeric suffix when the base slug is taken', function () {
    Tenant::factory()->create(['slug' => 'acme-grooming']);

    $this->post('/register', [
        'business_name' => 'Acme Grooming',
        'business_type' => 'groomer',
        'name' => 'Alex Owner',
        'email' => 'alex@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('onboarding.show', absolute: false));

    expect(Tenant::query()->where('slug', 'acme-grooming-2')->exists())->toBeTrue();
});

test('a failed owner insert does not leave a tenant behind', function () {
    User::creating(function (User $user) {
        if ($user->email === 'rollback@example.com') {
            throw new RuntimeException('forced failure');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/register', [
        'business_name' => 'Rollback Salon',
        'business_type' => 'groomer',
        'name' => 'Alex Owner',
        'email' => 'rollback@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(RuntimeException::class);

    expect(Tenant::query()->where('name', 'Rollback Salon')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'rollback@example.com')->exists())->toBeFalse();
});

test('registration stores a newly created vertical as the tenant type', function () {
    Vertical::factory()->create([
        'key' => 'barber',
        'label' => 'Barber',
        'subject_singular' => 'client',
        'subject_plural' => 'clients',
    ]);

    $this->post('/register', [
        'business_name' => 'Cut & Co',
        'business_type' => 'barber',
        'name' => 'Alex Owner',
        'email' => 'alex@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('onboarding.show', absolute: false));

    expect(Tenant::query()->where('name', 'Cut & Co')->first()?->type)->toBe('barber');
});

test('an already-registered email is answered with the door, not with a taken index', function () {
    User::factory()
        ->for(Tenant::factory()->create(['slug' => 'willow-street-grooming']), 'tenant')
        ->create(['email' => 'maya@example.com', 'role' => UserRole::Owner]);

    $response = $this->from('/register')->post('/register', [
        'business_name' => 'Willow Street Grooming Two',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertRedirect('/register');

    $response->assertSessionHasErrors([
        'email' => 'An account with this email already exists.',
    ]);

    expect(Tenant::query()->where('name', 'Willow Street Grooming Two')->exists())->toBeFalse();
    expect(User::withoutGlobalScopes()->where('email', 'maya@example.com')->count())->toBe(1);
});

test('the mismatch is reported on the confirmation, which is the field being read', function () {
    $response = $this->from('/register')->post('/register', [
        'business_name' => 'Willow Street Grooming',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-batery',
    ]);

    $response->assertSessionHasErrors([
        'password_confirmation' => 'Those two passwords do not match.',
    ]);
    $response->assertSessionDoesntHaveErrors('password');
});

test('a capitalised email is accepted and stored lowercase', function () {
    $this->post('/register', [
        'business_name' => 'Willow Street Grooming',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'Maya@Example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('onboarding.show', absolute: false));

    expect(User::withoutGlobalScopes()->where('email', 'maya@example.com')->exists())->toBeTrue();
});

test('registration lands on onboarding step one with the name and trade already in it', function () {
    $this->post('/register', [
        'business_name' => 'Willow Street Grooming',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('onboarding.show', absolute: false));

    $this->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Onboarding/Index')
            ->where('step', 'basics')
            ->where('basics.name', 'Willow Street Grooming')
            ->where('basics.type', 'groomer')
            ->where('basics.slug', 'willow-street-grooming')
        );
});

test('a signed-in tenant who has not finished setting up is sent back to the step they are on', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    $user->tenant->markOnboardingStep('basics');

    $tenants = Tenant::query()->count();

    actingAsTenant($user)->get('/register')->assertRedirect('/onboarding');

    actingAsTenant($user)->post('/register', [
        'business_name' => 'A Second Salon',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya+second@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect('/onboarding');

    expect(Tenant::query()->count())->toBe($tenants)
        ->and(Tenant::query()->where('name', 'A Second Salon')->exists())->toBeFalse();

    actingAsTenant($user)
        ->get('/onboarding')
        ->assertInertia(fn ($page) => $page->where('step', 'business'));
});

test('repeated failures lock the form with a sentence, not with a 429 page', function () {
    $attempt = fn () => $this->from('/register')->post('/register', [
        'business_name' => 'Willow Street Grooming',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'wrong-every-time',
    ]);

    for ($i = 0; $i < 10; $i++) {
        $attempt()->assertSessionHasErrors('password_confirmation');
    }

    $response = $attempt();

    $response->assertRedirect('/register');
    $response->assertStatus(302);
    expect(session('errors')->first('email'))->toMatch('/^Too many attempts\. Try again in \d+ (seconds|minutes)\.$/');

    $response->assertSessionDoesntHaveErrors('password_confirmation');
});

test('succeeding clears the failures that led up to it', function () {
    $payload = [
        'business_name' => 'Willow Street Grooming',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ];

    for ($i = 0; $i < 3; $i++) {
        $this->post('/register', [...$payload, 'password_confirmation' => 'nope'])
            ->assertSessionHasErrors('password_confirmation');
    }

    $this->post('/register', $payload)->assertRedirect(route('onboarding.show', absolute: false));

    expect(RateLimiter::attempts('register|maya@example.com|127.0.0.1'))->toBe(0);
});

test('registration stores the chosen currency and country against the tenant', function () {
    $this->post('/register', [
        'business_name' => 'Dublin Motor Works',
        'business_type' => 'garage',
        'currency' => 'EUR',
        'country' => 'IE',
        'name' => 'Niamh Byrne',
        'email' => 'niamh@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('onboarding.show', absolute: false));

    $tenant = Tenant::query()->where('slug', 'dublin-motor-works')->firstOrFail();

    expect($tenant->currency)->toBe('EUR')
        ->and($tenant->country)->toBe('IE')
        ->and($tenant->type)->toBe('garage');
});

test('registration falls back to the configured default when no currency is sent', function () {
    $this->post('/register', [
        'business_name' => 'Fallback Salon',
        'business_type' => 'groomer',
        'name' => 'Ada Price',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('onboarding.show', absolute: false));

    $tenant = Tenant::query()->where('slug', 'fallback-salon')->firstOrFail();

    expect($tenant->currency)->toBe('GBP')
        ->and($tenant->country)->toBe('GB');
});

test('registration refuses a country that does not settle in the chosen currency', function () {
    $this->post('/register', [
        'business_name' => 'Mismatched Motors',
        'business_type' => 'garage',
        'currency' => 'EUR',
        'country' => 'US',
        'name' => 'Sam Reed',
        'email' => 'sam@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('country');

    expect(Tenant::query()->where('slug', 'mismatched-motors')->exists())->toBeFalse();
});

test('registration refuses a currency the platform does not support', function () {
    $this->post('/register', [
        'business_name' => 'Yen Salon',
        'business_type' => 'groomer',
        'currency' => 'JPY',
        'country' => 'JP',
        'name' => 'Kei Tanaka',
        'email' => 'kei@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('currency');

    expect(Tenant::query()->where('slug', 'yen-salon')->exists())->toBeFalse();
});

test('the registration screen offers every configured currency and its countries', function () {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Register')
            ->where('defaultCurrency', 'GBP')
            ->has('currencies', 3)
            ->where('currencies.0.value', 'GBP')
            ->where('currencies.0.symbol', '£')
            ->has('currencyCountries.EUR')
            ->has('currencyCountries.GBP', 1));
});
