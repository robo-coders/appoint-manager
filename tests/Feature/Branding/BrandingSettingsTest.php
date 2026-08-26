<?php

use App\Models\Tenant;
use App\Models\User;

/**
 * Settings -> Branding: choosing the colour, and the rules about who may.
 */
function anOwner(?string $colour = null): User
{
    $tenant = Tenant::factory()->create(['brand_colour' => $colour, 'name' => 'Willow Street']);

    return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
}

it('shows the six presets and the current choice', function () {
    $user = anOwner('navy');

    actingAsTenant($user)
        ->get(route('settings.branding.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Branding')
            ->where('current', 'navy')
            ->where('businessName', 'Willow Street')
            ->has('presets', 6)
        );
});

it('saves each of the six presets', function (string $preset) {
    $user = anOwner();

    actingAsTenant($user)
        ->patch(route('settings.branding.update'), ['brand_colour' => $preset])
        ->assertRedirect(route('settings.branding.edit'))
        ->assertSessionHasNoErrors();

    expect($user->tenant->fresh()->brand_colour)->toBe($preset);
})->with(['forest', 'plum', 'navy', 'ochre', 'slate', 'clay']);

it('rejects a colour that is not one of the six', function (mixed $value) {
    $user = anOwner('navy');

    actingAsTenant($user)
        ->patch(route('settings.branding.update'), ['brand_colour' => $value])
        ->assertSessionHasErrors('brand_colour');

    // And the previous choice survives a rejected attempt.
    expect($user->tenant->fresh()->brand_colour)->toBe('navy');
})->with([
    'a colour we do not offer' => 'chartreuse',
    // The whole point of presets over a picker: a hex is not an answer here.
    'a raw hex' => '#ff0000',
    'a css injection' => 'navy); background:url(//evil',
    'a token reference' => 'var(--brand-navy)',
    'an uppercase preset' => 'Navy',
    'a number' => 7,
]);

it('lets a salon clear its choice and go back to ink', function () {
    $user = anOwner('clay');

    actingAsTenant($user)
        ->patch(route('settings.branding.update'), ['brand_colour' => ''])
        ->assertSessionHasNoErrors();

    expect($user->tenant->fresh()->brand_colour)->toBeNull();
});

it('will not let one salon set another salon colour', function () {
    $mine = anOwner('navy');
    $theirs = Tenant::factory()->create(['brand_colour' => 'forest']);

    actingAsTenant($mine)->patch(route('settings.branding.update'), ['brand_colour' => 'plum']);

    expect($theirs->fresh()->brand_colour)->toBe('forest');
    expect($mine->tenant->fresh()->brand_colour)->toBe('plum');
});

it('requires a signed-in operator', function () {
    $tenant = Tenant::factory()->create();

    $this->get(route('settings.branding.edit'))->assertRedirect();
    $this->patch(route('settings.branding.update'), ['brand_colour' => 'navy'])->assertRedirect();

    expect($tenant->fresh()->brand_colour)->toBeNull();
});

/**
 * "The admin app stays monochrome regardless of the tenant's choice."
 *
 * Asserted against the source rather than a rendered page, because the operator
 * app is client-rendered: a request test would only ever see the Inertia
 * payload, which is exactly the wrong place to look for a colour that would be
 * applied in a template. This reads the templates.
 */
it('keeps the tenant colour out of every operator screen but the branding preview', function () {
    $offenders = [];

    $files = array_merge(
        glob(resource_path('js/Pages/**/*.vue')) ?: [],
        glob(resource_path('js/Layouts/*.vue')) ?: [],
    );

    foreach ($files as $file) {
        $relative = str_replace(resource_path('js/'), '', $file);

        // The public booking page is the one surface that is meant to be branded.
        if (str_starts_with($relative, 'Pages/Public/')) {
            continue;
        }

        /*
         * The component gallery is not an operator screen — it is the library
         * looked at directly, and a library that cannot show its own brand
         * variant is missing the specimen that matters most here. It is not
         * served in production at all.
         */
        if (str_starts_with($relative, 'Pages/Dev/')) {
            continue;
        }

        $src = file_get_contents($file);

        /*
         * What counts is PAINTING with the brand, not mentioning the word. A
         * route name like `settings.branding.edit` is a link to the screen that
         * sets the colour; it is not the colour. Matching the bare word made
         * adding that link to the settings index a test failure, which is the
         * check crying wolf rather than catching a leak.
         *
         * These are the ways the token can actually reach a pixel.
         */
        $uses = preg_grep('/(?:bg|text|border|ring|fill|stroke|divide)-brand\b|--brand\b|tone="brand"|variant="brand"/', [$src]);

        if ($uses === []) {
            continue;
        }

        $offenders[$relative] = true;
    }

    /*
     * Exactly one operator screen may mention the brand at all, and only
     * because it shows a picture of the booking page. Anything else on this
     * list is the tenant's colour leaking into chrome she looks at all day.
     */
    expect(array_keys($offenders))->toBe(['Pages/Settings/Branding.vue']);

    // And in that one file it is confined to the preview, not the page root.
    $branding = file_get_contents(resource_path('js/Pages/Settings/Branding.vue'));

    expect(substr_count($branding, "'--brand'"))->toBe(1)
        ->and($branding)->toContain('inert');

    // The admin shell itself never carries a brand override.
    expect(file_get_contents(resource_path('views/app.blade.php')))->not->toContain('brand');
});

/**
 * The contrast gate, tested rather than trusted.
 *
 * A seventh preset that does not clear 4.5:1 against white button text must
 * fail `npm run check:contrast`. That threshold is the entire argument for
 * having six presets instead of a colour picker, so it is worth knowing that
 * the check enforcing it actually fires.
 */
it('fails check:contrast on a seventh preset that cannot carry white text', function () {
    $tokens = file_get_contents(resource_path('css/tokens.css'));

    // A pale yellow: about 1.8:1 against white, unreadable as a button fill.
    $withSeventh = str_replace(
        '--brand-clay: #8c4a32;',
        "--brand-clay: #8c4a32;\n    --brand-daffodil: #e8d44d;",
        $tokens
    );

    expect($withSeventh)->not->toBe($tokens);

    $path = tempnam(sys_get_temp_dir(), 'tokens-').'.css';
    file_put_contents($path, $withSeventh);

    try {
        $process = proc_open(
            ['node', 'scripts/check-contrast.mjs', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        array_map('fclose', $pipes);
        $exit = proc_close($process);
    } finally {
        unlink($path);
    }

    /*
     * The failure must name the preset and the ratio, not just exit non-zero.
     * The count is deliberately not asserted: a bad colour typically fails both
     * the white-text check and the legible-on-paper check, and pinning the
     * number would make this test about arithmetic rather than about the gate
     * firing.
     */
    expect($exit)->not->toBe(0)
        ->and($output)->toMatch('/FAIL\s+brand-fg on daffodil/')
        ->and($output)->toContain('FAILING');

    // The six that ship are still measured and still pass in the same run.
    expect($output)->toContain('ok   brand-fg on forest');
});

it('passes check:contrast on the six that ship', function () {
    $process = proc_open(
        ['node', 'scripts/check-contrast.mjs'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path()
    );

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    array_map('fclose', $pipes);

    expect(proc_close($process))->toBe(0)->and($output)->toContain('contrast: all pass');
});
