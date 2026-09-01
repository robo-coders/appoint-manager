<?php

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

/*
 * CSRF fires on a real product route once the testing short-circuit is off.
 *
 * `ValidateCsrfToken` skips the check when APP_ENV=testing. The rest of the
 * suite stays that way — every mutating request would 419 otherwise. This is
 * the canary that the middleware is still wired, not suite-wide coverage.
 * ErrorPageTest already proves the 419 page itself.
 */
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

/*
 * The silent login. APP_URL is localhost; the form is posted on 127.0.0.1.
 * `home_route()` used to return `app_url('diary')`, so a successful attempt
 * 302'd to the other host, the session cookie stayed behind, and the form
 * sat there with a cleared password and no error.
 */
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

/*
 * A stale CSRF token on the login form used to paint Laravel's 419 page
 * inside Inertia's error iframe, while `onFinish` cleared the password.
 * Dismiss the iframe and the form is blank. Bounce back to the form on this
 * host with a flash the page paints as a Callout instead.
 */
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

/*
 * The bug this guards: logging out used to answer a plain 302 to the marketing
 * homepage, which is Blade. From an Inertia visit the client followed that
 * redirect, got back an HTML document with no page component, and painted it
 * inside the authenticated shell — the tenant rail stayed on screen and only a
 * browser refresh escaped it.
 *
 * A status assertion alone cannot see that: the broken version was a perfectly
 * healthy 302 -> 200. What distinguishes the two is the *type* of response, so
 * that is what is asserted — 409 plus X-Inertia-Location, which is the only
 * thing the Inertia client turns into a real page load.
 */
test('logging out from an Inertia visit forces a full page load, not a partial swap', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->post('/logout');

    $this->assertGuest();

    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', marketing_url());
    // Not an Inertia page payload: nothing here for the client to swap in.
    $response->assertHeaderMissing('X-Inertia');
});

test('closing an account forces a full page load too', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    // Not the last owner — that guard is a different test's subject.
    User::factory()->create(['tenant_id' => $user->tenant_id, 'role' => UserRole::Owner]);

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->delete('/profile', ['password' => 'password']);

    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', marketing_url());
});
