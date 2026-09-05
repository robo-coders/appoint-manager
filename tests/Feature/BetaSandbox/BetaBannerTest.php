<?php

use Carbon\CarbonImmutable;

/**
 * The banner, and the settings tab beside it. See BETA_SANDBOX.md.
 *
 * `Components/BetaSandbox/Banner.vue` renders itself from `tenant.is_beta` on
 * the shared Inertia props, so that is what these assert: the flag is present
 * on every operator screen for a beta salon and absent for everybody else. A
 * component test would be checking that Vue's `v-if` works; this checks the
 * thing that could actually be wrong, which is whether the server tells the
 * shell anything at all.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/London'));
});

it('tells the shell a beta salon is in the beta, on every screen', function () {
    $salon = aBetaSalon();

    foreach (['dashboard', 'diary.index', 'settings.edit', 'bookings.index'] as $screen) {
        actingAsTenant($salon['staff'])
            ->get(route($screen))
            ->assertInertia(fn ($page) => $page->where('tenant.is_beta', true));
    }
});

it('never shows the banner to a salon outside the beta', function () {
    $salon = aSalon();

    foreach (['dashboard', 'diary.index', 'settings.edit', 'bookings.index'] as $screen) {
        actingAsTenant($salon['staff'])
            ->get(route($screen))
            ->assertInertia(fn ($page) => $page->where('tenant.is_beta', false));
    }
});

it('turns the banner off again when a salon leaves the beta', function () {
    $salon = aBetaSalon();

    $salon['tenant']->forceFill(['is_beta' => false])->save();

    actingAsTenant($salon['staff']->fresh())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('tenant.is_beta', false));
});
