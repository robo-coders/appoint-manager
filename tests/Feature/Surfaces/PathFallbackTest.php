<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\Surface;
use Illuminate\Support\Facades\Route;

/**
 * With APP_DOMAIN unset, every surface is served from APP_URL on the path
 * prefix it used before the split. This is the mode the test suite and a fresh
 * checkout run in, so nobody has to touch /etc/hosts to work on Appoint Manager.
 *
 * The suite's default config already has subdomain routing off, so these tests
 * assert the default rather than reconfiguring anything.
 */
it('has subdomain routing off by default', function () {
    expect(Surface::routingBySubdomain())->toBeFalse();
});

it('serves every surface from one host', function (string $path, int $status) {
    $this->get($path)->assertStatus($status);
})->with([
    ['/', 200],
    ['/pricing', 200],
    ['/dog-grooming', 200],
    ['/health', 200],
]);

it('keeps the booking page on its path prefix', function () {
    $tenant = Tenant::factory()->create();

    $this->get("/book/{$tenant->slug}")->assertOk();
});

it('keeps the console on its path prefix', function () {
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('keeps the app on the root, unprefixed', function () {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);

    actingAsTenant($owner)->get('/diary')->assertOk();
});

it('builds customer-facing booking links from APP_URL_BOOK without moving the operator host', function () {
    config(['app.surfaces.book' => 'https://phone.example.test']);

    $tenant = Tenant::factory()->create(['slug' => 'willow-street']);
    $base = rtrim((string) config('app.url'), '/');

    expect(book_url($tenant))->toBe('https://phone.example.test/book/willow-street')
        ->and(app_url('diary'))->toBe("{$base}/diary")
        ->and(admin_url())->toBe("{$base}/admin")
        ->and(marketing_url())->toBe($base)
        ->and(booking_url_is_loopback(book_url($tenant)))->toBeFalse();
});

it('treats a localhost booking link as unreachable from a phone', function () {
    expect(booking_url_is_loopback('http://localhost:8000/book/x'))->toBeTrue()
        ->and(booking_url_is_loopback('http://127.0.0.1:8000/book/x'))->toBeTrue()
        ->and(booking_url_is_loopback('https://book.example.test/x'))->toBeFalse();
});

it('uses one session cookie when there is only one host', function () {
    $this->get('/');

    expect(config('session.domain'))->toBeNull();
});

it('builds in-request paths, not APP_URL, so a host alias cannot drop the session', function () {
    expect(Surface::App->path('login'))->toBe('/login')
        ->and(Surface::App->path('diary'))->toBe('/diary')
        ->and(Surface::Admin->path('login'))->toBe('/admin/login')
        ->and(Surface::Admin->path())->toBe('/admin')
        ->and(home_route())->toBe('/diary');
});

it('resolves every named route', function () {
    $missing = collect([
        'marketing.home', 'marketing.pricing', 'login', 'register', 'dashboard', 'diary.index',
        'bookings.index', 'customers.index', 'services.index', 'staff.index', 'settings.edit',
        'public.booking.show', 'booking.manage.show', 'offer.show', 'booking.preview',
        'super-admin.index', 'admin.login', 'impersonation.start', 'impersonation.stop',
        'stripe.webhook', 'health',
    ])->reject(fn (string $name) => Route::has($name));

    expect($missing->all())->toBe([]);
});
