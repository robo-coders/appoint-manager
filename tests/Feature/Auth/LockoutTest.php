<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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

test('the route limiters sit above the lockouts they back', function () {
    $limit = fn (string $name, string $email) => app(Illuminate\Cache\RateLimiter::class)
        ->limiter($name)(Request::create('/', 'POST', ['email' => $email]))
        ->maxAttempts;

    expect($limit('login', 'test@example.com'))->toBeGreaterThan(5)
        ->and($limit('admin-login', 'test@example.com'))->toBeGreaterThan(3);
});
