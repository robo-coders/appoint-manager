<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\BookingQr;
use App\Support\Surface;

function aBookingLinkOwner(array $tenant = []): User
{
    $salon = Tenant::factory()->create(array_merge([
        'name' => 'Willow Street',
        'slug' => 'willow-street',
        'booking_page_live' => true,
    ], $tenant));

    return User::factory()->create(['tenant_id' => $salon->id, 'role' => 'owner']);
}

it('puts the same slug URL on settings and the console', function () {
    $owner = aBookingLinkOwner();
    $tenant = $owner->tenant;
    $expected = Surface::bookUrlFor($tenant);

    expect($tenant->publicBookingUrl())->toBe($expected)
        ->and(book_url($tenant))->toBe($expected);

    actingAsTenant($owner)
        ->get(route('settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Index')
            ->where('booking_link.url', $expected)
            ->where('booking_link.live', true)
            ->where('booking_link.qr_url', route('settings.booking-link.qr'))
            ->where('booking_link.qr_download_url', route('settings.booking-link.qr', ['download' => 1]))
        );

    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);

    $this->actingAs($admin)
        ->get(route('super-admin.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('SuperAdmin/Index')
            ->where('tenants.0.booking_url', $expected)
        );
});

it('serves a PNG QR for a live booking page and withholds it when the page is dark', function () {
    $owner = aBookingLinkOwner();

    $live = actingAsTenant($owner)->get(route('settings.booking-link.qr'));
    $live->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(substr($live->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    actingAsTenant($owner)
        ->get(route('settings.booking-link.qr', ['download' => 1]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="willow-street.png"');

    $owner->tenant->forceFill([
        'booking_page_live' => false,
        'preview_token' => 'preview-token-test',
    ])->save();

    actingAsTenant($owner)
        ->get(route('settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('booking_link.url', Surface::bookUrlFor($owner->tenant))
            ->where('booking_link.live', false)
            ->where('booking_link.qr_url', null)
            ->where('booking_link.qr_download_url', null)
        );

    actingAsTenant($owner)->get(route('settings.booking-link.qr'))->assertNotFound();
});

it('opens the public booking page at the slug URL the settings screen shows', function () {
    $owner = aBookingLinkOwner();
    $url = $owner->tenant->publicBookingUrl();

    expect($url)->toContain('/book/willow-street');

    $this->get('/book/willow-street')
        ->assertOk()
        ->assertSee('Willow Street', false);
});

it('does not open the slug URL while the booking page is dark', function () {
    aBookingLinkOwner([
        'booking_page_live' => false,
        'preview_token' => 'preview-token-test',
        'onboarding_completed_at' => now(),
    ]);

    $this->get('/book/willow-street')->assertNotFound();
    $this->get(route('booking.preview', 'preview-token-test'))->assertOk();
});

it('encodes the booking URL into a PNG', function () {
    $url = 'http://book.example.test/willow-street';
    $png = BookingQr::png($url);

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and($png)->not->toBe(BookingQr::png('http://book.example.test/other-salon'));
});
