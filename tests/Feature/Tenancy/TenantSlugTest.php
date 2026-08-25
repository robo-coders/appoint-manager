<?php

use App\Models\Tenant;
use App\Support\TenantSlug;

it('generates a unique slug with a numeric suffix', function () {
    Tenant::factory()->create(['slug' => 'paws']);

    expect(TenantSlug::generate('Paws'))->toBe('paws-2')
        ->and(TenantSlug::generate('!!!'))->toBe('business');
});
