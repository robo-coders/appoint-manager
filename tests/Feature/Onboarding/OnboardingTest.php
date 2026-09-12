<?php

use App\Enums\UserRole;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;

/** @return list<array{weekday: int, open: bool, start_time: string, end_time: string}> */
function aWeek(int ...$open): array
{
    return collect(range(1, 7))
        ->map(fn (int $day) => [
            'weekday' => $day,
            'open' => in_array($day, $open, true),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ])
        ->all();
}

it('redirects incomplete tenants to onboarding after login', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete())
        ->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->get(route('dashboard'))->assertRedirect(route('onboarding.show'));
});

it('saves each onboarding step and can resume', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    $tenant = $user->tenant;

    actingAsTenant($user)
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Onboarding/Index')
            ->where('step', 'basics')
            ->has('steps', 5)
            ->has('onboardingSteps', 4));

    $this->patch(route('onboarding.basics'), [
        'name' => 'Paws & Whiskers Grooming',
        'slug' => 'paws-and-whiskers',
        'type' => 'groomer',
        'hours' => aWeek(1, 2, 3, 4, 5),
    ])->assertRedirect(route('onboarding.show', ['step' => 'business']));

    expect($tenant->fresh())
        ->name->toBe('Paws & Whiskers Grooming')
        ->slug->toBe('paws-and-whiskers')
        ->and($tenant->fresh()->onboardingCompletedSteps())->toContain('basics')
        ->and(AvailabilityRule::query()->count())->toBe(5);

    actingAsTenant($user->fresh())
        ->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page->where('step', 'business'));

    $this->patch(route('onboarding.business'), [
        'timezone' => 'Europe/London',
        'phone' => '020 7946 0123',
        'address_line_1' => '12 Willow Street',
        'city' => 'London',
        'postcode' => 'E8 3AA',
    ])->assertRedirect(route('onboarding.show', ['step' => 'services']));

    expect($tenant->fresh()->phone)->toBe('020 7946 0123');

    $this->patch(route('onboarding.services'), [
        'name' => 'Full groom — medium coat',
        'duration_minutes' => 90,
        'price' => 4200,
        'deposit_amount' => 1000,
    ])->assertRedirect(route('onboarding.show', ['step' => 'link']));

    expect(Service::query()->count())->toBe(1);

    $this->post(route('onboarding.complete'), ['slug' => 'paws-and-whiskers'])
        ->assertRedirect(route('diary.index'));

    expect($tenant->fresh()->hasCompletedOnboarding())->toBeTrue()
        ->and($tenant->fresh()->booking_page_live)->toBeTrue();

    $this->get(route('diary.index'))->assertOk();
});

it('resumes at the first unfinished step after logging out and back in', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.basics'), [
        'name' => 'Paws & Whiskers Grooming',
        'slug' => 'paws-and-whiskers',
        'type' => 'groomer',
        'hours' => aWeek(1, 2, 3),
    ])->assertSessionHasNoErrors();

    $this->patch(route('onboarding.business'), [
        'timezone' => 'Europe/London',
    ])->assertRedirect(route('onboarding.show', ['step' => 'services']));

    $this->post(route('logout'));
    $this->assertGuest();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->get(route('dashboard'))->assertRedirect(route('onboarding.show'));

    $this->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('step', 'services')
            ->where('basics.slug', 'paws-and-whiskers')
            ->where('basics.name', 'Paws & Whiskers Grooming'));
});

it('sends a completed step back with its saved answers when you go back to it', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.basics'), [
        'name' => 'Paws & Whiskers Grooming',
        'slug' => 'paws-and-whiskers',
        'type' => 'groomer',
        'hours' => aWeek(4),
    ])->assertSessionHasNoErrors();

    $this->get(route('onboarding.show', ['step' => 'basics']))
        ->assertInertia(fn ($page) => $page
            ->where('step', 'basics')
            ->where('basics.hours.3.open', true)
            ->where('basics.hours.0.open', false));
});

it('will not open a step that is further ahead than the first unfinished one', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)
        ->get(route('onboarding.show', ['step' => 'link']))
        ->assertInertia(fn ($page) => $page->where('step', 'basics'));
});

it('rejects a slug another salon already has, and says which field', function () {
    Tenant::factory()->create(['slug' => 'paws-and-whiskers']);

    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.basics'), [
        'name' => 'Paws & Whiskers Grooming',
        'slug' => 'paws-and-whiskers',
        'type' => 'groomer',
        'hours' => aWeek(1),
    ])->assertSessionHasErrors('slug');

    expect($user->tenant->fresh()->onboardingCompletedSteps())->not->toContain('basics');
});

it('counts a soft-deleted salon as still holding its slug', function () {
    Tenant::factory()->create(['slug' => 'paws-and-whiskers'])->delete();

    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.basics'), [
        'name' => 'Paws & Whiskers Grooming',
        'slug' => 'paws-and-whiskers',
        'type' => 'groomer',
        'hours' => aWeek(1),
    ])->assertSessionHasErrors('slug');
});

it('answers whether a slug is free, and suggests one that is', function () {
    Tenant::factory()->create(['slug' => 'paws-and-whiskers']);

    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)
        ->getJson(route('onboarding.slug', ['slug' => 'paws-and-whiskers']))
        ->assertOk()
        ->assertJson(['available' => false, 'suggestion' => 'paws-and-whiskers-2']);

    $this->getJson(route('onboarding.slug', ['slug' => 'barks-and-bubbles']))
        ->assertOk()
        ->assertJson(['available' => true, 'suggestion' => null]);

    $this->getJson(route('onboarding.slug', ['slug' => $user->tenant->slug]))
        ->assertJson(['available' => true]);
});

it('catches a slug taken between step one and the last step', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.basics'), [
        'name' => 'Paws & Whiskers Grooming',
        'slug' => 'paws-and-whiskers',
        'type' => 'groomer',
        'hours' => aWeek(1),
    ])->assertSessionHasNoErrors();

    $this->patch(route('onboarding.business'), ['timezone' => 'Europe/London']);
    $this->patch(route('onboarding.services'), [
        'name' => 'Full groom',
        'duration_minutes' => 60,
        'price' => 3000,
        'deposit_amount' => 0,
    ]);

    $user->tenant->forceFill(['slug' => 'paws-and-whiskers-mine'])->save();
    Tenant::factory()->create(['slug' => 'paws-and-whiskers']);

    $this->post(route('onboarding.complete'), ['slug' => 'paws-and-whiskers'])
        ->assertSessionHasErrors('slug');

    expect($user->tenant->fresh()->hasCompletedOnboarding())->toBeFalse();

    $this->get(route('diary.index'))->assertRedirect(route('onboarding.show'));
});

it('blocks a week with every day closed', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.basics'), [
        'name' => 'Paws & Whiskers Grooming',
        'slug' => 'paws-and-whiskers',
        'type' => 'groomer',
        'hours' => aWeek(),
    ])->assertSessionHasErrors('hours');

    expect(AvailabilityRule::query()->count())->toBe(0);
});

it('blocks a day that closes before it opens', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    $hours = aWeek(1);
    $hours[0]['start_time'] = '17:00';
    $hours[0]['end_time'] = '09:00';

    actingAsTenant($user)->patch(route('onboarding.basics'), [
        'name' => 'Paws & Whiskers Grooming',
        'slug' => 'paws-and-whiskers',
        'type' => 'groomer',
        'hours' => $hours,
    ])->assertSessionHasErrors('hours.0.end_time');
});

it('blocks a deposit larger than the price, and allows one of zero', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.services'), [
        'name' => 'Full groom',
        'duration_minutes' => 90,
        'price' => 4200,
        'deposit_amount' => 5000,
    ])->assertSessionHasErrors('deposit_amount');

    $this->patch(route('onboarding.services'), [
        'name' => 'Full groom',
        'duration_minutes' => 90,
        'price' => 4200,
        'deposit_amount' => 0,
    ])->assertSessionHasNoErrors();

    expect(Service::query()->sole()->deposit_amount->amount)->toBe(0);
});

it('prefills the first service from the vertical rather than an empty form', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    $default = Vertical::query()->where('key', 'groomer')->firstOrFail()->default_services[0];

    actingAsTenant($user)
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('service.name', $default['name'])
            ->where('service.duration_minutes', $default['duration_minutes'])
            ->where('service.price', $default['price'])
            ->where('service.deposit_amount', $default['deposit_amount']));
});

it('lands a new signup in onboarding, not in the app', function () {
    $this->post(route('register'), [
        'business_name' => 'Paws & Whiskers Grooming',
        'business_type' => 'groomer',
        'name' => 'Maya Chen',
        'email' => 'maya@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('onboarding.show'));

    $tenant = Tenant::query()->where('email', 'maya@example.com')->sole();

    expect($tenant->hasCompletedOnboarding())->toBeFalse()
        ->and($tenant->slug)->toBe('paws-whiskers-grooming');

    foreach (['dashboard', 'diary.index', 'bookings.index', 'customers.index'] as $name) {
        $this->get(route($name))->assertRedirect(route('onboarding.show'));
    }
});

it('sends a signed-in tenant who has finished setup away from onboarding', function () {
    $user = User::factory()->create();

    actingAsTenant($user)
        ->get(route('onboarding.show'))
        ->assertRedirect(route('diary.index'));
});

it('no longer answers on the retired hours endpoint', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)
        ->patch('/onboarding/hours', [
            'rules' => [
                ['user_id' => $user->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
            ],
        ])
        ->assertNotFound();

    expect(AvailabilityRule::query()->count())->toBe(0);
});
