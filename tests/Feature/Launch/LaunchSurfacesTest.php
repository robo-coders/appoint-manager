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
    $this->get('/')->assertOk()->assertSee('The empty slot fills itself.');
    $this->get('/pricing')->assertOk()->assertSee('£29');
    $this->get('/how-it-works')->assertOk()->assertSee('Three steps. No manual work.');

    $grooming = $this->get('/dog-grooming')->assertOk();

    $grooming->assertSee('cancellation, sold twice');

    foreach (['dentist', 'physio', 'barber', 'tattoo', 'clinic', 'salon chair'] as $elsewhere) {
        $grooming->assertDontSee($elsewhere);
    }

    $this->get('/sitemap.xml')->assertOk();
    $this->get('/robots.txt')->assertOk();
    $this->get('/health')->assertOk();
});
