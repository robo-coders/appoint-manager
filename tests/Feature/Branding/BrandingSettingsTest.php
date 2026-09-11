<?php

use App\Models\Tenant;
use App\Models\User;

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

    expect($user->tenant->fresh()->brand_colour)->toBe('navy');
})->with([
    'a colour we do not offer' => 'chartreuse',
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

it('keeps the tenant colour out of every operator screen but the branding preview', function () {
    $offenders = [];

    $files = array_merge(
        glob(resource_path('js/Pages/**/*.vue')) ?: [],
        glob(resource_path('js/Layouts/*.vue')) ?: [],
    );

    foreach ($files as $file) {
        $relative = str_replace(resource_path('js/'), '', $file);

        if (str_starts_with($relative, 'Pages/Public/')) {
            continue;
        }

        if (str_starts_with($relative, 'Pages/Dev/')) {
            continue;
        }

        $src = file_get_contents($file);

        $uses = preg_grep('/(?:bg|text|border|ring|fill|stroke|divide)-brand\b|--brand\b|tone="brand"|variant="brand"/', [$src]);

        if ($uses === []) {
            continue;
        }

        $offenders[$relative] = true;
    }

    expect(array_keys($offenders))->toBe(['Pages/Settings/Branding.vue']);

    $branding = file_get_contents(resource_path('js/Pages/Settings/Branding.vue'));

    expect(substr_count($branding, "'--brand'"))->toBe(1)
        ->and($branding)->toContain('inert');

    expect(file_get_contents(resource_path('views/app.blade.php')))->not->toContain('brand');
});

it('fails check:contrast on a seventh preset that cannot carry white text', function () {
    $tokens = file_get_contents(resource_path('css/tokens.css'));

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

    expect($exit)->not->toBe(0)
        ->and($output)->toMatch('/FAIL\s+brand-fg on daffodil/')
        ->and($output)->toContain('FAILING');

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
