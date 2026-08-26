<?php

use App\Http\Middleware\SecurityHeaders;
use App\Support\Surface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;

/**
 * The CSP carves out the Vite dev server so `npm run dev` works locally. That
 * carve-out is a hole in a security header, so most of what follows is here to
 * prove it cannot reach any environment but local-with-the-dev-server-running.
 */

/** Run the middleware for a surface and return the CSP it set. */
function cspFor(Surface $surface): string
{
    config([
        'app.subdomain_routing' => true,
        'app.surfaces.marketing' => 'https://appoint-manager.test',
        'app.surfaces.app' => 'https://app.appoint-manager.test',
        'app.surfaces.book' => 'https://book.appoint-manager.test',
        'app.surfaces.admin' => 'https://admin.appoint-manager.test',
    ]);

    $request = Request::create($surface->url().'/');

    $response = app(SecurityHeaders::class)->handle($request, fn () => new Response('ok'));

    return (string) $response->headers->get('Content-Security-Policy');
}

/**
 * Point Vite at a hot file holding $contents, as `npm run dev` would write it.
 * `withVite()` first because the base TestCase disables Vite, and its stand-in
 * ignores `useHotFile()` — which would leave these tests reading whatever
 * `public/hot` happens to hold on the machine running them.
 */
function useHotFile(?string $contents): void
{
    test()->withVite();

    $path = tempnam(sys_get_temp_dir(), 'vite-hot-');

    if ($contents === null) {
        unlink($path);
    } else {
        file_put_contents($path, $contents.PHP_EOL);
    }

    Vite::useHotFile($path);
}

/**
 * The one that matters. A local carve-out that leaks to production is a worse
 * outcome than a broken dev setup, so production is asserted explicitly and by
 * name rather than inferred from "the test environment is not local".
 */
it('never puts a localhost or websocket origin in the production policy', function (Surface $surface) {
    app()->detectEnvironment(fn () => 'production');
    useHotFile('http://localhost:5173');

    $policy = cspFor($surface);

    expect($policy)
        ->not->toContain('localhost')
        ->not->toContain('127.0.0.1')
        ->not->toContain('0.0.0.0')
        ->not->toContain('ws://')
        ->not->toContain('wss://')
        ->not->toContain(':5173');

    // Every directive that the carve-out widens is checked as a whole token,
    // so a dev origin cannot hide inside one that happens to allow a CDN.
    $directives = collect(explode('; ', $policy))
        ->mapWithKeys(fn (string $directive) => [explode(' ', $directive)[0] => $directive]);

    foreach (['script-src', 'style-src', 'connect-src'] as $directive) {
        expect($directives[$directive])->not->toMatch('/\b(?:localhost|127\.0\.0\.1|ws:|wss:)/');
    }
})->with(Surface::cases());

it('serves a production response with no dev origin in the header', function () {
    app()->detectEnvironment(fn () => 'production');
    useHotFile('http://localhost:5173');

    expect((string) $this->get('/')->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->not->toContain('localhost')
        ->not->toContain('ws://');
});

it('does not leak a dev origin into a non-local environment even when a hot file exists', function (Surface $surface) {
    useHotFile('http://localhost:5173');

    expect(app()->environment('local'))->toBeFalse();

    expect(cspFor($surface))
        ->not->toContain('localhost')
        ->not->toContain('127.0.0.1')
        ->not->toContain('ws://')
        ->not->toContain('wss://')
        ->not->toContain(':5173');
})->with(Surface::cases());

it('does not add a dev origin in local when the dev server is not running', function () {
    app()->detectEnvironment(fn () => 'local');
    useHotFile(null);

    expect(cspFor(Surface::App))
        ->not->toContain('localhost')
        ->not->toContain('ws://');
});

it('adds the dev origin to script-src, style-src and connect-src in local while the dev server runs', function (Surface $surface) {
    app()->detectEnvironment(fn () => 'local');
    useHotFile('http://localhost:5173');

    $directives = collect(explode('; ', cspFor($surface)))
        ->mapWithKeys(fn (string $directive) => [explode(' ', $directive)[0] => $directive]);

    expect($directives['script-src'])->toContain('http://localhost:5173')
        ->and($directives['style-src'])->toContain('http://localhost:5173')
        ->and($directives['connect-src'])->toContain('http://localhost:5173')
        ->and($directives['connect-src'])->toContain('ws://localhost:5173');
})->with(Surface::cases());

it('keeps the third-party allowances each surface already had', function () {
    app()->detectEnvironment(fn () => 'local');
    useHotFile('http://localhost:5173');

    expect(cspFor(Surface::Book))->toContain('https://js.stripe.com')
        ->and(cspFor(Surface::Marketing))->toContain('https://plausible.io');
});

it('derives the dev origin from the hot file rather than assuming a port', function () {
    app()->detectEnvironment(fn () => 'local');
    useHotFile('http://127.0.0.1:5199');

    expect(cspFor(Surface::App))
        ->toContain('http://127.0.0.1:5199')
        ->toContain('ws://127.0.0.1:5199')
        ->not->toContain(':5173');
});

it('uses a secure websocket origin when the dev server is served over https', function () {
    app()->detectEnvironment(fn () => 'local');
    useHotFile('https://localhost:5173');

    expect(cspFor(Surface::App))
        ->toContain('wss://localhost:5173')
        ->not->toContain('ws://localhost:5173');
});

it('ignores a hot file that does not hold an http origin', function (string $contents) {
    app()->detectEnvironment(fn () => 'local');
    useHotFile($contents);

    $withGarbage = cspFor(Surface::App);

    useHotFile(null);

    expect($withGarbage)->toBe(cspFor(Surface::App));
})->with(['undefined', 'javascript:alert(1)', '', 'http://', '  ']);

it('sets the policy on a real response', function () {
    expect((string) $this->get('/')->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('localhost:5173');
});
