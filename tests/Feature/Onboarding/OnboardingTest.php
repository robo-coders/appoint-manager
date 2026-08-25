<?php

use App\Enums\UserRole;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;

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
        ->for(Tenant::factory()->onboardingIncomplete())
        ->create();

    actingAsTenant($user)
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Onboarding/Index')
            ->where('step', 'business'));

    $this->patch(route('onboarding.business'), [
        'timezone' => 'Europe/London',
        'phone' => '020 7946 0123',
        'address_line_1' => '12 Willow Street',
        'city' => 'London',
        'postcode' => 'E8 3AA',
    ])->assertRedirect(route('onboarding.show', ['step' => 'services']));

    expect($user->tenant->fresh()->phone)->toBe('020 7946 0123')
        ->and($user->tenant->fresh()->onboardingCompletedSteps())->toContain('business');

    actingAsTenant($user->fresh())
        ->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page->where('step', 'services'));

    $this->patch(route('onboarding.services'), [
        'services' => [
            [
                'name' => 'Bath',
                'duration_minutes' => 45,
                'price' => 2500,
                'deposit_amount' => 1000,
            ],
        ],
    ])->assertRedirect(route('onboarding.show', ['step' => 'staff']));

    expect(Service::query()->count())->toBe(1);

    $this->patch(route('onboarding.staff'), [
        'staff' => [
            ['name' => 'Jordan Blake', 'email' => 'jordan@example.com'],
        ],
    ])->assertRedirect(route('onboarding.show', ['step' => 'hours']));

    expect(User::query()->where('email', 'jordan@example.com')->first())
        ->role->toBe(UserRole::Staff);

    $this->patch(route('onboarding.hours'), [
        'rules' => [
            [
                'user_id' => $user->id,
                'weekday' => 1,
                'start_time' => '09:00',
                'end_time' => '17:00',
            ],
        ],
    ])->assertRedirect(route('diary.index'));

    expect($user->tenant->fresh()->hasCompletedOnboarding())->toBeTrue()
        ->and(AvailabilityRule::query()->count())->toBe(1);

    $this->get(route('diary.index'))->assertOk();
});

it('sends default services as bound values, not empty placeholders', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete())
        ->create();

    $defaults = config('verticals.groomer.default_services');

    actingAsTenant($user)
        ->get(route('onboarding.show', ['step' => 'services']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Onboarding/Index')
            ->where('services.0.name', $defaults[0]['name'])
            ->where('services.0.duration_minutes', $defaults[0]['duration_minutes'])
            ->where('services.0.price', $defaults[0]['price'])
            ->where('services.0.deposit_amount', $defaults[0]['deposit_amount']));
});
