<?php

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('login rejects a POST with no csrf token when the testing short-circuit is off', function () {
    $original = app()['env'];
    app()['env'] = 'local';

    try {
        $this->post('/login', [
            'email' => 'a@example.com',
            'password' => 'secret',
        ])->assertStatus(419);
    } finally {
        app()['env'] = $original;
    }
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('diary.index', absolute: false));
});

test('login sends incomplete tenants to onboarding', function () {
    $user = User::factory()
        ->for(Tenant::factory()->onboardingIncomplete())
        ->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('diary.index', absolute: false));

    $this->get(route('diary.index', absolute: false))
        ->assertRedirect(route('onboarding.show', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')->first('email'))->toContain('credentials');
});

test('login stays on the host the form was posted to, even when that is not APP_URL', function () {
    config([
        'app.url' => 'http://localhost',
        'app.surfaces.app' => 'http://localhost',
        'app.surfaces.marketing' => 'http://localhost',
        'app.surfaces.admin' => 'http://localhost',
        'app.surfaces.book' => 'http://localhost',
    ]);

    $user = User::factory()->create();

    $response = $this->post('http://127.0.0.1/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $location = $response->headers->get('Location');
    expect($location)->toContain('127.0.0.1')
        ->and($location)->not->toContain('localhost');
});

test('an unauthenticated visit stays on the host it arrived on', function () {
    config([
        'app.url' => 'http://localhost',
        'app.surfaces.app' => 'http://localhost',
        'app.surfaces.marketing' => 'http://localhost',
        'app.surfaces.admin' => 'http://localhost',
        'app.surfaces.book' => 'http://localhost',
    ]);

    $location = $this->get('http://127.0.0.1/diary')->headers->get('Location');

    expect($location)->toContain('127.0.0.1')
        ->and($location)->toEndWith('/login')
        ->and($location)->not->toContain('localhost');
});

test('an inertia login with a stale csrf token returns to the form naming the expiry', function () {
    $original = app()['env'];
    app()['env'] = 'local';

    try {
        $this->post('/login', [
            'email' => 'a@example.com',
            'password' => 'secret',
        ], [
            'X-Inertia' => 'true',
            'Referer' => 'http://localhost/login',
        ])->assertStatus(409)
            ->assertHeader('X-Inertia-Location');

        expect(session('auth_notice.kind'))->toBe('expired');

        $this->get('/login')->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('authNotice.kind', 'expired')
            ->where('authNotice.title', 'Your session expired'));
    } finally {
        app()['env'] = $original;
    }
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect(marketing_url());
});

test('logging out from an Inertia visit forces a full page load, not a partial swap', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->post('/logout');

    $this->assertGuest();

    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', marketing_url());
    $response->assertHeaderMissing('X-Inertia');
});

test('closing an account forces a full page load too', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    User::factory()->create(['tenant_id' => $user->tenant_id, 'role' => UserRole::Owner]);

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->delete('/profile', ['password' => 'password']);

    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', marketing_url());
});
