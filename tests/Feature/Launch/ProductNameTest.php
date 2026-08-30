<?php

use App\Mail\BookingConfirmedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;

/**
 * The name people read comes from `config('product.name')`, nowhere else.
 *
 * `app.name` is the machine identity. It slugs into cache prefixes and
 * session cookie names, so a wordmark must not be wired to it. These tests
 * set the two to different values and then ask every surface that renders
 * a name which one it used.
 */
const DISPLAY = 'Northwind Desk';

const MACHINE = 'Appoint Manager';

beforeEach(function () {
    config([
        'product.name' => DISPLAY,
        'app.name' => MACHINE,
    ]);
});

it('prints the configured name on the marketing wordmark and title, not the machine name', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('>'.DISPLAY.'</a>')
        ->toContain(DISPLAY)
        ->not->toContain(MACHINE);
});

it('names the error page from product.name', function () {
    Route::middleware('web')->get('/__name-probe', fn () => abort(503));

    $html = $this->get('/__name-probe')->assertStatus(503)->getContent();

    expect($html)
        ->toContain(DISPLAY.' is down for a few minutes')
        ->toContain(DISPLAY)
        ->not->toContain(MACHINE);
});

it('signs customer email from product.name', function () {
    $tenant = Tenant::factory()->create(['name' => 'Paw & Order']);
    app(TenantContext::class)->set($tenant);

    $staff = User::factory()->create(['tenant_id' => $tenant->id]);
    $service = Service::factory()->create(['tenant_id' => $tenant->id]);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $booking = Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $staff->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
    ]);
    $booking->setRelation('service', $service)
        ->setRelation('staff', $staff)
        ->setRelation('customer', $customer);

    app(TenantContext::class)->clear();

    $mail = new BookingConfirmedMail($booking, $tenant);
    $html = $mail->render();
    $text = view($mail->content()->text, array_merge($mail->buildViewData(), $mail->content()->with))->render();

    expect($html)
        ->toContain('Sent by '.DISPLAY.' on behalf of Paw &amp; Order.')
        ->not->toContain(MACHINE)
        ->and($text)->toContain('Sent by '.DISPLAY.' on behalf of Paw & Order.');
});

it('composes the PWA manifest from product.name', function () {
    $this->get('/site.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJson([
            'name' => DISPLAY,
            'short_name' => DISPLAY,
        ]);
});

it('shares product.name with the Inertia chrome, not app.name', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('appName', DISPLAY)
            ->where('auth_panel.body', DISPLAY.' is appointment software for small businesses '
                .'that lose money when clients do not arrive. One diary, one place, and a '
                .'deposit taken before the appointment rather than chased after it.'));
});
