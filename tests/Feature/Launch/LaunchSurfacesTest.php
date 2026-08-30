<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;

it('gates super admin and logs impersonation', function () {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->for($tenant)->owner()->create();
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($owner)->get(route('super-admin.index'))->assertForbidden();

    $this->actingAs($admin)->get(route('super-admin.index'))->assertOk();

    // The console cannot set a cookie for the app surface, so impersonation
    // hands off a short-lived signed link that the app exchanges for a session.
    // Cross-surface behaviour is covered in detail in tests/Feature/Surfaces.
    $handoff = $this->actingAs($admin)
        ->post(route('super-admin.impersonate', $tenant))
        ->headers->get('Location');

    expect($handoff)->toContain('/impersonate/');

    $this->flushSession();
    $this->get($handoff)->assertRedirect(app_url('diary'));

    expect(session('impersonator_id'))->toBe($admin->id)
        ->and(AuditLog::query()->where('action', 'impersonate.start')->count())->toBe(1);

    $this->post(route('impersonation.stop'))->assertRedirect(admin_url());
});

it('renders marketing pages without mentioning other verticals on dog grooming', function () {
    // Headlines updated in phase 11: the home page leads with recovered revenue
    // rather than no-shows, and the ledger it used to lead with is on /pricing.
    // The point of this test is the vertical isolation below, not the wording.
    $this->get('/')->assertOk()->assertSee('One refilled slot covers the month.');
    $this->get('/pricing')->assertOk()->assertSee('£39');

    $grooming = $this->get('/dog-grooming')->assertOk();

    $grooming->assertSee('cancellation, sold twice');

    // The reason this surface is Blade and not Vue: a vertical's copy must not
    // leak. Every other trade we might add, asserted rather than only dentists.
    foreach (['dentist', 'physio', 'barber', 'tattoo', 'clinic', 'salon chair'] as $elsewhere) {
        $grooming->assertDontSee($elsewhere);
    }

    $this->get('/sitemap.xml')->assertOk();
    $this->get('/robots.txt')->assertOk();
    $this->get('/health')->assertOk();
});
