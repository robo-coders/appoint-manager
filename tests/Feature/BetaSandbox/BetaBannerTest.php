<?php

use Carbon\CarbonImmutable;

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
