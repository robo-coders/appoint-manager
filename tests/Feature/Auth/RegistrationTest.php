<?php

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Timezones;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertOk();
});

test('registration creates a tenant shell and owner atomically and redirects to onboarding', function () {
    Event::fake([Registered::class]);

    $response = $this->post('/register', [
        'name' => 'Maya Chen',
        'email' => 'maya@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.show', absolute: false));

    $tenant = Tenant::query()->where('email', 'maya@example.com')->first();
    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('')
        ->and($tenant->type)->toBe('')
        ->and($tenant->timezone)->toBe(Timezones::forCountry('GB'))
        ->and($tenant->currency)->toBe('GBP')
        ->and($tenant->country)->toBe('GB')
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
    Tenant::factory()->create(['slug' => 'business']);

    $this->post('/register', [
        'name' => 'Alex Owner',
        'email' => 'alex@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('onboarding.show', absolute: false));

    expect(Tenant::query()->where('slug', 'business-2')->exists())->toBeTrue();
});

test('a failed owner insert does not leave a tenant behind', function () {
    User::creating(function (User $user) {
        if ($user->email === 'rollback@example.com') {
            throw new RuntimeException('forced failure');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/register', [
        'name' => 'Alex Owner',
        'email' => 'rollback@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(RuntimeException::class);

    expect(Tenant::query()->where('email', 'rollback@example.com')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'rollback@example.com')->exists())->toBeFalse();
});

test('registration ignores business fields that belong in onboarding', function () {
    $this->post('/register', [
        'business_name' => 'Cut & Co',
        'business_type' => 'barber',
        'currency' => 'EUR',
        'country' => 'IE',
        'name' => 'Alex Owner',
        'email' => 'alex@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('onboarding.show', absolute: false));

    $tenant = Tenant::query()->where('email', 'alex@example.com')->first();

    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('')
        ->and($tenant->type)->toBe('')
        ->and($tenant->currency)->toBe('GBP')
        ->and($tenant->country)->toBe('GB');
});

test('an already-registered email is answered with the door, not with a taken index', function () {
    User::factory()
        ->for(Tenant::factory()->create(['slug' => 'willow-street-grooming']), 'tenant')
        ->create(['email' => 'maya@example.com', 'role' => UserRole::Owner]);

    $response = $this->from('/register')->post('/register', [
        'name' => 'Maya Chen',
        'email' => 'maya@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertRedirect('/register');

    $response->assertSessionHasErrors([
        'email' => 'An account with this email already exists.',
    ]);

    expect(Tenant::query()->count())->toBe(1);
    expect(User::withoutGlobalScopes()->where('email', 'maya@example.com')->count())->toBe(1);
});

test('the mismatch is reported on the confirmation, which is the field being read', function () {
    $response = $this->from('/register')->post('/register', [
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
        'name' => 'Maya Chen',
        'email' => 'Maya@Example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('onboarding.show', absolute: false));

    expect(User::withoutGlobalScopes()->where('email', 'maya@example.com')->exists())->toBeTrue();
});

test('registration lands on onboarding step one without business answers filled in', function () {
    $this->post('/register', [
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
            ->where('basics.name', '')
            ->where('basics.type', '')
            ->where('basics.currency', 'GBP')
            ->where('business.country', 'GB')
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
        'name' => 'Maya Chen',
        'email' => 'maya+second@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect('/onboarding');

    expect(Tenant::query()->count())->toBe($tenants)
        ->and(User::withoutGlobalScopes()->where('email', 'maya+second@example.com')->exists())->toBeFalse();

    actingAsTenant($user)
        ->get('/onboarding')
        ->assertInertia(fn ($page) => $page->where('step', 'business'));
});

test('repeated failures lock the form with a sentence, not with a 429 page', function () {
    $attempt = fn () => $this->from('/register')->post('/register', [
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

test('the registration screen is account fields only', function () {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Register')
            ->missing('currencies')
            ->missing('currencyCountries')
            ->missing('businessTypes')
            ->missing('defaultCurrency'));
});
