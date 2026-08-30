<?php

use App\Models\Tenant;
use App\Support\BrandPalette;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — the tenant's accent on its public booking page.
 *
 * The accent is a per-tenant value rendered into a public page's style
 * attribute, which makes it two things worth testing carefully: a cross-tenant
 * leak risk, and an injection surface. Most of what follows is about those
 * rather than about whether the colour is pretty.
 */
it('renders no brand override for a tenant that has not chosen a colour', function () {
    $tenant = Tenant::factory()->create(['name' => 'Willow Street', 'brand_colour' => null]);

    $html = $this->get(route('public.booking.show', $tenant->slug))->assertOk()->getContent();

    /*
     * Nothing is emitted at all, rather than `--brand: var(--ink)`. The default
     * lives in tokens.css, so the absence of an override IS the ink case, and
     * an unbranded page has one fewer thing on it.
     */
    expect($html)->not->toContain('--brand:');

    // The mark is still there — it is just painted with --brand's own default.
    expect($html)->toContain('bg-brand');
});

it('renders each of the six presets as a token reference, never a hex', function (string $preset) {
    $tenant = Tenant::factory()->create(['name' => 'Willow Street', 'brand_colour' => $preset]);

    $html = $this->get(route('public.booking.show', $tenant->slug))->assertOk()->getContent();

    expect($html)->toContain("--brand: var(--brand-{$preset})");

    /*
     * And the page must NOT contain the colour itself. If this preset's hex
     * ever appears here, something resolved the token server-side and
     * tokens.css has stopped being the only file that knows what forest is.
     *
     * The hex is read out of tokens.css rather than written down, so this
     * assertion keeps working when a preset is retuned.
     */
    preg_match("/--brand-{$preset}:\s*([^;]+);/", file_get_contents(resource_path('css/tokens.css')), $m);

    expect(trim($m[1]))->toStartWith('#');
    expect($html)->not->toContain(trim($m[1]));
})
    /*
     * Listed rather than pulled from BrandPalette, because a Pest dataset is
     * resolved before the application boots and `resource_path()` is not
     * available that early. The test below proves this list is the same six
     * tokens.css declares, so the two cannot drift apart.
     */
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

    /*
     * Straight past the model and the validator, as a hand-edited row or a
     * preset renamed in tokens.css would be. The page must not emit
     * `var(--brand-chartreuse)`, which resolves to nothing and paints an
     * invisible button on an invisible mark.
     */
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

    /*
     * manage-booking and offer render through the same shell. They deliberately
     * do not pass a brand, so the shell must not reach for the tenant's colour
     * on its own — the accent belongs to the shopfront, not to a page about one
     * specific appointment.
     */
    $shell = file_get_contents(resource_path('views/public-shell.blade.php'));

    expect($shell)->toContain('$brand = $brand ?? null;');

    foreach (['manage-booking', 'offer', 'offer-taken'] as $view) {
        expect(file_get_contents(resource_path("views/{$view}.blade.php")))
            ->not->toContain('brandVariable');
    }

    expect(file_get_contents(resource_path('views/booking.blade.php')))->toContain('brandVariable');
    expect($tenant->brandVariable())->toBe('var(--brand-navy)');
});

/**
 * The rollback, on a table that has rows in it.
 *
 * `down()` is the half of a migration nobody runs until the worst possible
 * moment, so it is worth having run once on populated data. What it must do is
 * drop one column and leave every other row and column untouched; what it
 * cannot avoid doing is discarding the choices themselves, which is why the
 * migration says so in as many words.
 */
it('rolls back and re-applies on a populated tenants table', function () {
    $willow = Tenant::factory()->create(['name' => 'Willow Street', 'brand_colour' => 'navy']);
    $fern = Tenant::factory()->create(['name' => 'Fern & Feather', 'brand_colour' => null]);

    $migration = require database_path('migrations/2026_08_25_000000_add_brand_colour_to_tenants.php');

    $migration->down();

    expect(Schema::hasColumn('tenants', 'brand_colour'))->toBeFalse();

    // Every other column survives, on both rows.
    expect(Tenant::query()->orderBy('id')->pluck('name')->all())
        ->toBe(['Willow Street', 'Fern & Feather']);
    expect($willow->fresh()->slug)->toBe($willow->slug);

    $migration->up();

    expect(Schema::hasColumn('tenants', 'brand_colour'))->toBeTrue()
        ->and(Tenant::query()->count())->toBe(2);

    /*
     * The colours are gone, and that is the documented consequence rather than
     * a bug: every booking page is back to ink, which is what those pages
     * looked like before the column existed. Salons re-pick.
     */
    expect($willow->fresh()->brand_colour)->toBeNull()
        ->and($fern->fresh()->brand_colour)->toBeNull();
});
