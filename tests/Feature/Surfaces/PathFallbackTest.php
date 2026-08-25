<?php

use App\Models\Tenant;

/**
 * With APP_DOMAIN unset, every surface is served from APP_URL on the path
 * prefix it used before the split. This is the mode the test suite and a fresh
 * checkout run in, so nobody has to touch /etc/hosts to work on Kestrel.
 *
 * The suite's default config already has subdomain routing off, so these tests
 * assert the default rather than reconfiguring anything.
 */
it('has subdomain routing off by default', function () {
    expect(App\Support\Surface::routingBySubdomain())->toBeFalse();
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
    $admin = App\Models\User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('keeps the app on the root, unprefixed', function () {
    $tenant = Tenant::factory()->create();
    $owner = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

    actingAsTenant($owner)->get('/diary')->assertOk();
});

it('builds every helper URL against the single host', function () {
    $tenant = Tenant::factory()->create(['slug' => 'willow-street']);
    $base = rtrim(config('app.url'), '/');

    expect(marketing_url())->toBe($base)
        ->and(app_url('diary'))->toBe("{$base}/diary")
        ->and(admin_url())->toBe("{$base}/admin")
        ->and(book_url($tenant))->toBe("{$base}/book/willow-street")
        ->and(book_url(null, 'b/abc'))->toBe("{$base}/book/b/abc");
});

it('uses one session cookie when there is only one host', function () {
    $this->get('/');

    expect(config('session.domain'))->toBeNull();
});

it('resolves every named route', function () {
    $missing = collect([
        'marketing.home', 'marketing.pricing', 'login', 'register', 'dashboard', 'diary.index',
        'bookings.index', 'customers.index', 'services.index', 'staff.index', 'settings.edit',
        'public.booking.show', 'booking.manage.show', 'offer.show', 'booking.preview',
        'super-admin.index', 'admin.login', 'impersonation.start', 'impersonation.stop',
        'stripe.webhook', 'health',
    ])->reject(fn (string $name) => Illuminate\Support\Facades\Route::has($name));

    expect($missing->all())->toBe([]);
});
