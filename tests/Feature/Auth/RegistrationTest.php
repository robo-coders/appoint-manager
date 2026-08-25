<?php

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertOk();
});

test('registration creates a tenant and owner atomically and redirects to onboarding', function () {
    Event::fake([Registered::class]);

    $response = $this->post('/register', [
        'business_name' => 'Willow Street Grooming',
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

    $owner = User::query()->where('email', 'maya@example.com')->first();
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
        'name' => 'Alex Owner',
        'email' => 'rollback@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(RuntimeException::class);

    expect(Tenant::query()->where('name', 'Rollback Salon')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'rollback@example.com')->exists())->toBeFalse();
});
