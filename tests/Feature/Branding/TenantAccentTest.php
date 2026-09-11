<?php

use App\Models\Tenant;
use App\Support\BrandPalette;
use Illuminate\Support\Facades\Schema;

it('renders no brand override for a tenant that has not chosen a colour', function () {
    $tenant = Tenant::factory()->create(['name' => 'Willow Street', 'brand_colour' => null]);

    $html = $this->get(route('public.booking.show', $tenant->slug))->assertOk()->getContent();

    expect($html)->not->toContain('--brand:');

    expect($html)->toContain('bg-brand');
});

it('renders each of the six presets as a token reference, never a hex', function (string $preset) {
    $tenant = Tenant::factory()->create(['name' => 'Willow Street', 'brand_colour' => $preset]);

    $html = $this->get(route('public.booking.show', $tenant->slug))->assertOk()->getContent();

    expect($html)->toContain("--brand: var(--brand-{$preset})");

    preg_match("/--brand-{$preset}:\s*([^;]+);/", file_get_contents(resource_path('css/tokens.css')), $m);

    expect(trim($m[1]))->toStartWith('#');
    expect($html)->not->toContain(trim($m[1]));
})
    ->with(['forest', 'plum', 'navy', 'ochre', 'slate', 'clay']);

it('offers exactly the six presets, read from tokens.css', function () {
    expect(BrandPalette::names())
        ->toEqualCanonicalizing(['forest', 'plum', 'navy', 'ochre', 'slate', 'clay'])
        ->toHaveCount(6);
});

it('does not leak one tenant colour into another tenant page', function () {
    $forest = Tenant::factory()->create(['brand_colour' => 'forest']);
    $plum = Tenant::factory()->create(['brand_colour' => 'plum']);
    $none = Tenant::factory()->create(['brand_colour' => null]);

    $forestHtml = $this->get(route('public.booking.show', $forest->slug))->assertOk()->getContent();
    $plumHtml = $this->get(route('public.booking.show', $plum->slug))->assertOk()->getContent();
    $noneHtml = $this->get(route('public.booking.show', $none->slug))->assertOk()->getContent();

    expect($forestHtml)->toContain('var(--brand-forest)')->not->toContain('var(--brand-plum)');
    expect($plumHtml)->toContain('var(--brand-plum)')->not->toContain('var(--brand-forest)');
    expect($noneHtml)->not->toContain('var(--brand-forest)')->not->toContain('var(--brand-plum)');
});

it('falls back to ink when the stored colour is not one of the six', function () {
    $tenant = Tenant::factory()->create(['brand_colour' => 'forest']);

    Tenant::query()->whereKey($tenant->id)->update(['brand_colour' => 'chartreuse']);

    $html = $this->get(route('public.booking.show', $tenant->slug))->assertOk()->getContent();

    expect($html)->not->toContain('--brand:')->not->toContain('chartreuse');
});

it('does not put a stored value into the page without validating it', function () {
    $tenant = Tenant::factory()->create();

    Tenant::query()->whereKey($tenant->id)->update([
        'brand_colour' => 'x);url(//evil',
    ]);

    $html = $this->get(route('public.booking.show', $tenant->slug))->assertOk()->getContent();

    expect($html)->not->toContain('evil')->not->toContain('--brand:');
});

it('keeps the accent off the pages that share the public shell', function () {
    $tenant = Tenant::factory()->create(['brand_colour' => 'navy']);

    $shell = file_get_contents(resource_path('views/public-shell.blade.php'));

    expect($shell)->toContain('$brand = $brand ?? null;');

    foreach (['manage-booking', 'offer', 'offer-taken'] as $view) {
        expect(file_get_contents(resource_path("views/{$view}.blade.php")))
            ->not->toContain('brandVariable');
    }

    expect(file_get_contents(resource_path('views/booking.blade.php')))->toContain('brandVariable');
    expect($tenant->brandVariable())->toBe('var(--brand-navy)');
});

it('rolls back and re-applies on a populated tenants table', function () {
    $willow = Tenant::factory()->create(['name' => 'Willow Street', 'brand_colour' => 'navy']);
    $fern = Tenant::factory()->create(['name' => 'Fern & Feather', 'brand_colour' => null]);

    $migration = require database_path('migrations/2026_08_25_000000_add_brand_colour_to_tenants.php');

    $migration->down();

    expect(Schema::hasColumn('tenants', 'brand_colour'))->toBeFalse();

    expect(Tenant::query()->orderBy('id')->pluck('name')->all())
        ->toBe(['Willow Street', 'Fern & Feather']);
    expect($willow->fresh()->slug)->toBe($willow->slug);

    $migration->up();

    expect(Schema::hasColumn('tenants', 'brand_colour'))->toBeTrue()
        ->and(Tenant::query()->count())->toBe(2);

    expect($willow->fresh()->brand_colour)->toBeNull()
        ->and($fern->fresh()->brand_colour)->toBeNull();
});
