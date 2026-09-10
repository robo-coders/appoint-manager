<?php

use App\Enums\UserRole;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;

/**
 * Setting up a business: five steps, saved one at a time.
 *
 * The flow is `basics → business → services → staff → link`, and the shape of
 * this suite follows from one property: **the server, not the browser, knows
 * where you are.** Every step writes to the tenant on continue and
 * `OnboardingController::show()` rebuilds from that, which is what makes a
 * closed tab, a fresh login and a Back button all survivable in the same way.
 */

/**
 * Seven days, open 09:00-17:00 on the ones named and shut on the rest.
 *
 * @return list<array{weekday: int, open: bool, start_time: string, end_time: string}>
 */
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
            ->where('step', 'basics'));

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
    ])->assertRedirect(route('onboarding.show', ['step' => 'staff']));

    expect(Service::query()->count())->toBe(1);

    $this->patch(route('onboarding.staff'), [
        'staff' => ['name' => 'Erin MacKay', 'email' => 'erin@example.com'],
    ])->assertRedirect(route('onboarding.show', ['step' => 'link']));

    expect(User::query()->where('email', 'erin@example.com')->first())
        ->role->toBe(UserRole::Staff)
        ->can_see_customer_contacts->toBeTrue();

    $this->post(route('onboarding.complete'), ['slug' => 'paws-and-whiskers'])
        ->assertRedirect(route('diary.index'));

    expect($tenant->fresh()->hasCompletedOnboarding())->toBeTrue()
        ->and($tenant->fresh()->booking_page_live)->toBeTrue();

    $this->get(route('diary.index'))->assertOk();
});

/*
 * Resume. The one property the whole flow rests on, tested the way it actually
 * fails in the wild — not by re-reading the page in the same session, but by
 * leaving entirely and coming back to a new one.
 */
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

    // Not step one, and not the diary: the first step that has not been saved.
    $this->get(route('dashboard'))->assertRedirect(route('onboarding.show'));

    $this->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('step', 'services')
            // And what was saved comes back with it, rather than as defaults.
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

/* ------------------------------------------------------------------ slugs -- */

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

    // Its own slug is not a collision with itself.
    $this->getJson(route('onboarding.slug', ['slug' => $user->tenant->slug]))
        ->assertJson(['available' => true]);
});

/*
 * The race the final step exists to catch: the slug was free on step one, and
 * somebody else took it while this salon was filling in the middle three. The
 * unique index would make that a 500 on the last click of setup; it has to be a
 * named error on a field instead, so the screen can carry the person back to
 * the step where that field lives.
 */
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
    $this->patch(route('onboarding.staff'), ['staff' => null]);

    /*
     * Another salon takes the address in the meantime. Our tenant has to let go
     * of it first — it is holding the slug from step one, and the unique index
     * is what this test is about not hitting.
     */
    $user->tenant->forceFill(['slug' => 'paws-and-whiskers-mine'])->save();
    Tenant::factory()->create(['slug' => 'paws-and-whiskers']);

    $this->post(route('onboarding.complete'), ['slug' => 'paws-and-whiskers'])
        ->assertSessionHasErrors('slug');

    expect($user->tenant->fresh()->hasCompletedOnboarding())->toBeFalse();

    // And the app is still gated, rather than half-open.
    $this->get(route('diary.index'))->assertRedirect(route('onboarding.show'));
});

/* ------------------------------------------------------------------ steps -- */

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

it('names a duplicate staff email rather than silently skipping the invite', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.staff'), [
        'staff' => ['name' => 'The owner again', 'email' => $user->email],
    ])->assertSessionHasErrors('staff.email');

    expect(User::query()->count())->toBe(1)
        ->and($user->tenant->fresh()->onboardingCompletedSteps())->not->toContain('staff');
});

it('rejects a malformed staff email', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.staff'), [
        'staff' => ['name' => 'Erin MacKay', 'email' => 'erin@'],
    ])->assertSessionHasErrors('staff.email');
});

it('treats an empty staff row as a skip, and a half-filled one as a mistake', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.staff'), [
        'staff' => ['name' => '', 'email' => ''],
    ])->assertRedirect(route('onboarding.show', ['step' => 'link']));

    expect(User::query()->count())->toBe(1);

    $this->patch(route('onboarding.staff'), [
        'staff' => ['name' => 'Erin MacKay', 'email' => ''],
    ])->assertSessionHasErrors('staff.email');
});

it('stores the contact-visibility answer on the invited member of staff', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete(), 'tenant')
        ->create(['role' => UserRole::Owner]);

    actingAsTenant($user)->patch(route('onboarding.staff'), [
        'staff' => [
            'name' => 'Erin MacKay',
            'email' => 'erin@example.com',
            'can_see_customer_contacts' => false,
        ],
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'erin@example.com')->sole()->can_see_customer_contacts)
        ->toBeFalse();
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

/* ------------------------------------------------------------- the cutover -- */

/*
 * Registration is still the front door — it is where an account comes from —
 * but it is no longer allowed to be an exit. The two facts that make onboarding
 * the flow rather than a flow: signing up lands here, and the app stays shut
 * until the last step is saved.
 */
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

    // Every app route is behind the gate, not just the dashboard.
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

    // The step it belonged to is part of `basics` now. The route is gone, and
    // a route that is gone must 404 rather than quietly resolving to something.
    actingAsTenant($user)
        ->patch('/onboarding/hours', [
            'rules' => [
                ['user_id' => $user->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
            ],
        ])
        ->assertNotFound();

    expect(AvailabilityRule::query()->count())->toBe(0);
});
