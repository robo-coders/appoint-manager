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

    // No tenant context out here — the assertion is a background process, and
    // `User` fails closed like every other model now. It says which tenant it
    // means rather than reading across all of them.
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

/**
 * ── The four failures, and where each one lands ───────────────────────────
 *
 * `Auth/Register.vue` renders every error under the field it belongs to, so
 * what these assert is not "the request was rejected" but *which key* carries
 * the message and *what it says* — the two things the page reads.
 */
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

    /*
     * The exact sentence, because the page matches on the words "already
     * exists" to decide which field slot renders the link to /login. A
     * rewording here is a rewording there — see the note in
     * `RegisterRequest::messages()`.
     */
    $response->assertSessionHasErrors([
        'email' => 'An account with this email already exists.',
    ]);

    // And nothing was created on the way to saying so.
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

    /*
     * The prefill is not passed through the session: the tenant row created by
     * registration *is* the prefill, and `OnboardingController::show()` reads
     * the two columns back. So this asserts the actual contract between the two
     * screens — that step one opens as a confirmation rather than as the same
     * two questions a second time.
     */
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

    // The bookmark case: /register is the link they were sent, and they are
    // signed in. One redirect, straight into the flow — not to the diary and
    // out again, and certainly not to a second account.
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

    // And the step it opens on is the first one they have not finished, not the
    // one they already saved.
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

    // Ten failures are allowed, because six fields typed for the first time
    // earn more than a password box does. The eleventh is the lockout.
    for ($i = 0; $i < 10; $i++) {
        $attempt()->assertSessionHasErrors('password_confirmation');
    }

    $response = $attempt();

    /*
     * A redirect back to the form carrying a message — which is what the page
     * can render — rather than the 429 error page, which is what the throttle
     * middleware alone would have produced. The middleware is still there at
     * 30 a minute; it is the flood stop, and this fires first.
     */
    $response->assertRedirect('/register');
    $response->assertStatus(302);
    expect(session('errors')->first('email'))->toMatch('/^Too many attempts\. Try again in \d+ (seconds|minutes)\.$/');

    // The lockout is the whole answer: it is not also a field-level failure.
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

    // Three near misses, then the real thing.
    for ($i = 0; $i < 3; $i++) {
        $this->post('/register', [...$payload, 'password_confirmation' => 'nope'])
            ->assertSessionHasErrors('password_confirmation');
    }

    $this->post('/register', $payload)->assertRedirect(route('onboarding.show', absolute: false));

    expect(RateLimiter::attempts('register|maya@example.com|127.0.0.1'))->toBe(0);
});
