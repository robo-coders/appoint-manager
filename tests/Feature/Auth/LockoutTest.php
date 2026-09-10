<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The lockout has to reach the screen that draws it.
 *
 * Both login pages render an ink callout — the mockups' "account locked", the
 * hard stop that is deliberately not terracotta — and for both of them the
 * message behind it was unreachable. Each route carried a `throttle:` limiter
 * set to the same number of tries the application allowed, on its own cache
 * key, running before the controller. The sixth POST to `/login` and the fourth
 * to `/admin/login` were answered 429 with a full-page error, so the callout
 * could not fire on the last failure (it had not happened) or on the one after
 * (it never arrived).
 *
 * These tests assert the thing that was actually broken: a 302 back to the form
 * with `auth.throttle` on `email`, rather than a 429. A status assertion is the
 * whole point — a test that only checked "the request was refused" passed
 * against the bug.
 */
beforeEach(function () {
    RateLimiter::clear('test@example.com|127.0.0.1');
    RateLimiter::clear('admin|test@example.com|127.0.0.1');
});

test('the sixth sign-in returns to the form with the lockout message, not a 429', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(302);
    }

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    $response->assertStatus(302)->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Too many');
    $this->assertGuest();
});

test('the console locks out after three failures, on the page rather than on an error screen', function () {
    User::factory()->create(['email' => 'test@example.com', 'tenant_id' => null, 'is_super_admin' => true]);

    foreach (range(1, 3) as $attempt) {
        $this->post('/admin/login', ['email' => 'test@example.com', 'password' => 'wrong-password'])
            ->assertStatus(302);
    }

    $response = $this->post('/admin/login', ['email' => 'test@example.com', 'password' => 'wrong-password']);

    $response->assertStatus(302)->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Too many');
    $this->assertGuest();
});

/*
 * The console's own hole, and the reason the counter is hit in two places.
 *
 * A salon owner's password is the credential an attacker is most likely to
 * already hold, and `AdminSessionController` authenticates before it checks
 * `is_super_admin` — so "right password, wrong person" was a path that reached
 * `Auth::attempt()` and left no mark. Uncounted, it is an unlimited oracle on
 * the one door that opens every tenant's diary.
 */
test('a non-staff account with the right password is counted against the console lockout', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->create([
        'email' => 'test@example.com',
        'tenant_id' => $tenant->id,
        'is_super_admin' => false,
    ]);

    foreach (range(1, 3) as $attempt) {
        $this->post('/admin/login', ['email' => 'test@example.com', 'password' => 'password'])
            ->assertStatus(302);
        expect(session('errors')->first('email'))->toContain('credentials');
    }

    $this->post('/admin/login', ['email' => 'test@example.com', 'password' => 'password'])
        ->assertStatus(302);

    expect(session('errors')->first('email'))->toContain('Too many');
    $this->assertGuest();
});

test('a real sign-in clears the console lockout counter', function () {
    User::factory()->create(['email' => 'test@example.com', 'tenant_id' => null, 'is_super_admin' => true]);

    $this->post('/admin/login', ['email' => 'test@example.com', 'password' => 'wrong-password']);
    expect(RateLimiter::attempts('admin|test@example.com|127.0.0.1'))->toBe(1);

    $this->post('/admin/login', ['email' => 'test@example.com', 'password' => 'password']);

    $this->assertAuthenticated();
    expect(RateLimiter::attempts('admin|test@example.com|127.0.0.1'))->toBe(0);
});

/*
 * The middleware is still there. It is a flood stop now rather than the
 * lockout, and its ceiling has to stay above it — set them equal again and the
 * bug is back, silently, because every test above would still pass on the
 * *first* five requests.
 */
test('the route limiters sit above the lockouts they back', function () {
    $limit = fn (string $name, string $email) => app(Illuminate\Cache\RateLimiter::class)
        ->limiter($name)(Request::create('/', 'POST', ['email' => $email]))
        ->maxAttempts;

    expect($limit('login', 'test@example.com'))->toBeGreaterThan(5)
        ->and($limit('admin-login', 'test@example.com'))->toBeGreaterThan(3);
});
