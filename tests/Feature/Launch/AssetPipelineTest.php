<?php

use App\Models\Tenant;

/**
 * The rest of the suite runs withoutVite(), which stubs @vite out entirely. That is
 * why a Blade page could reference an asset that was never registered as a Vite
 * input and still pass every test, while returning a 500 in production.
 *
 * These tests resolve assets for real against the built manifest.
 */
beforeEach(function () {
    $this->withVite();

    if (! file_exists(public_path('build/manifest.json'))) {
        $this->markTestSkipped('Run `npm run build` first — these assert against the real manifest.');
    }

    // A stale `hot` file makes Vite serve dev-server URLs and skip the manifest,
    // which would hide exactly the failure these tests exist to catch.
    if (file_exists(public_path('hot'))) {
        $this->markTestSkipped('Vite dev server is running; the manifest is not in use.');
    }
});

it('resolves every asset the marketing pages ask for', function (string $path) {
    $this->get($path)->assertOk();
})->with([
    '/',
    '/pricing',
    '/dog-grooming',
    '/about',
    '/contact',
    '/privacy',
    '/terms',
]);

it('links a real stylesheet on the marketing pages', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toMatch('/<link[^>]+rel="stylesheet"[^>]+href="[^"]*\/build\/assets\/[^"]+\.css"/');
});

it('serves the built stylesheet that the marketing pages link to', function () {
    preg_match('/href="[^"]*(\/build\/assets\/[^"]+\.css)"/', $this->get('/')->getContent(), $m);

    expect($m[1] ?? null)->not->toBeNull()
        ->and(file_exists(public_path(ltrim($m[1], '/'))))->toBeTrue();
});

it('resolves assets on the public booking shell', function () {
    $salon = aSalon();

    $this->get(route('public.booking.show', $salon['tenant']->slug))->assertOk();
});

it('resolves assets on the offer-taken page', function () {
    $html = view('offer-taken', ['message' => 'Taken'])->render();

    expect($html)->toContain('/build/assets/')
        ->and($html)->toContain('rel="stylesheet"');
});

it('keeps every @vite reference in Blade resolvable', function () {
    $referenced = [];

    foreach (glob(resource_path('views/**/*.blade.php')) + glob(resource_path('views/*.blade.php')) as $file) {
        preg_match_all("/@vite\(\[([^\]]+)\]\)/", (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $group) {
            preg_match_all("/'([^']+\.(?:css|ts|js))'/", $group, $paths);
            foreach ($paths[1] as $path) {
                $referenced[$path][] = str_replace(resource_path(), 'resources', $file);
            }
        }
    }

    expect($referenced)->not->toBeEmpty();

    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    $unresolvable = [];

    foreach ($referenced as $path => $usedBy) {
        if (! array_key_exists($path, $manifest)) {
            $unresolvable[] = $path.' (referenced by '.implode(', ', array_unique($usedBy)).')';
        }
    }

    // Anything listed here renders a 500 in production: @vite cannot resolve it.
    expect($unresolvable)->toBe([]);
});
