<?php

use App\Support\DesignTokens;
use App\Support\ErrorPage;
use App\Support\Surface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Every error status renders our page, on every surface, and not the stock one.
 *
 * `resources/views/errors/` did not exist, so a 403, 404, 419, 429, 500 and 503
 * were all somebody else's grey page — the product's visual language ending at
 * exactly the moment somebody most needs to trust it.
 *
 * **Over real HTTP, not through the handler object.** The first version of this
 * called `app(ExceptionHandler::class)->render(...)` directly and every test
 * failed against a page that was neither ours nor Laravel's. The reason is
 * worth recording: `nunomaduro/collision` rebinds `ExceptionHandler` whenever
 * the app is running in the console, which includes the whole Pest suite, and
 * its adapter renders Symfony's built-in page instead of `errors::{status}`.
 * So the object a test can reach by hand is not the object that serves a
 * browser. Going through the test client exercises the real HTTP kernel, which
 * is the only thing that proves anything here.
 *
 * The routes below are registered by the tests that need them. A route that
 * exists only for tests must not ship, and one defined inside a test does not.
 */

/** Register a throwaway route that aborts, and fetch it. */
function hitting(int $code, string $path = '/__error-probe')
{
    Route::middleware('web')->get($path, fn () => abort($code));

    return test()->get($path);
}

/** Laravel's and Symfony's stock pages carry none of this; ours carries all of it. */
function isOurs(string $html): bool
{
    return str_contains($html, 'class="eyebrow"') && str_contains($html, '--paper:');
}

beforeEach(fn () => config(['app.subdomain_routing' => false, 'app.debug' => false]));

/*
|--------------------------------------------------------------------------
| Ours, not the framework's
|--------------------------------------------------------------------------
*/

it('renders our own page for every status', function (int $code) {
    $response = hitting($code);

    $response->assertStatus($code);

    $html = $response->getContent();

    expect(isOurs($html))->toBeTrue('status '.$code.' fell through to a stock error page')
        // The eyebrow is the tell: the status and its name, in mono, ours.
        ->and($html)->toContain((string) $code)
        // The product's own name, whatever it is configured as — `APP_NAME` is
        // not set under phpunit.xml, so a literal here would assert nothing.
        ->and($html)->toContain(config('app.name'));
})->with([403, 404, 419, 429, 500, 503]);

it('renders our 404 for a URL that simply does not exist', function () {
    $response = $this->get('/no-such-operator-page');

    $response->assertNotFound();

    expect(isOurs($response->getContent()))->toBeTrue()
        ->and($response->getContent())->toContain('nothing at this address');
});

/*
|--------------------------------------------------------------------------
| Three audiences, three pages
|--------------------------------------------------------------------------
|
| The person who hit the error is not one person, and the same status means a
| different thing to each of them.
|
*/

it('tells a customer on the booking host that a salon link is wrong', function () {
    $html = $this->get('/book/no-such-salon')->getContent();

    expect($html)
        ->toContain('booking link')
        ->toContain('salon')
        /*
         * And offers them nothing of ours. Our marketing site sells appointment
         * software to salon owners; this is a customer holding a bad link, and
         * "Appoint Manager home" is a tap that helps nobody.
         */
        ->not->toContain('Today’s diary')
        ->not->toContain('Sign in');
});

it('gives an operator the diary back, not a generic home button', function () {
    $html = $this->get('/no-such-operator-page')->getContent();

    expect($html)
        ->toContain('Today’s diary')
        ->toContain('All bookings')
        ->not->toContain('Go home');
});

it('reads differently for a customer than for an operator', function () {
    $customer = $this->get('/book/no-such-salon')->getContent();
    $operator = $this->get('/no-such-operator-page')->getContent();

    expect($customer)->not->toBe($operator)
        ->and($customer)->toContain('does not go anywhere')
        ->and($operator)->toContain('nothing at this address');
});

it('keeps the console terse', function () {
    expect($this->get('/admin/no-such-console-page')->getContent())->toContain('No route matches.');
});

/*
 * The booking surface's copy is asserted against `ErrorPage` rather than over
 * HTTP, and that is not laziness: `routes/book.php` ends in a `/{tenant_slug}`
 * catch-all, so a probe route under `/book/...` is matched by *that* first and
 * comes back as a 404 for a salon that does not exist. Which is correct
 * behaviour and the wrong test. The wiring is already proven by the statuses
 * above; what is left to check here is the copy, and the copy lives here.
 */
it('sends a customer to the salon on 503, and an operator to wait for the deploy', function () {
    $customer = ErrorPage::for(Request::create(config('app.url').'/book/paw'), 503);
    $operator = ErrorPage::for(Request::create(config('app.url').'/diary'), 503);

    expect($customer['surface'])->toBe(Surface::Book)
        ->and($customer['body'])->toContain('calling the salon')
        ->and($operator['surface'])->toBe(Surface::App)
        ->and($operator['body'])->toContain('planned pause');
});

it('offers a customer no way out at all, because we are not one', function () {
    $customer = ErrorPage::for(Request::create(config('app.url').'/book/paw'), 404);

    expect($customer['ways'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| 419 — the one that matters most
|--------------------------------------------------------------------------
|
| An operator whose session went stale mid-shift landed in a stock "419 | Page
| Expired": grey, framework-branded, and a genuine dead end. What they need is
| not a home button, it is the page they were on.
|
*/

it('offers a way back rather than a dead end', function () {
    $html = hitting(419)->getContent();

    expect($html)
        ->toContain('Sign in and carry on')
        ->toContain('back where you were')
        ->toContain('signed out while that page was open');
});

/*
 * A real stale form, which means leaving the testing environment — the CSRF
 * middleware is bypassed inside it, so a POST without a token is accepted and
 * the case under test cannot happen at all.
 */
it('stores the page you were on, so signing in returns you to it', function () {
    app()['env'] = 'local';

    $was = config('app.url').'/diary?date=2026-08-26';

    Route::middleware('web')->post('/__csrf-probe', fn () => 'ok');

    $response = $this->withHeader('referer', $was)->post('/__csrf-probe', []);

    $response->assertStatus(419);

    // The referrer, not the URL that failed. A 419 is almost always a POST, and
    // sending somebody back to `POST /bookings` helps nobody.
    expect(session('url.intended'))->toBe($was)
        ->and($response->getContent())->toContain('/diary');
});

/*
 * `url.intended` is followed after login without further checks, so a referrer
 * from another origin is an open redirect handed to us for free.
 */
it('refuses a referrer from another origin', function () {
    app()['env'] = 'local';

    Route::middleware('web')->post('/__csrf-probe', fn () => 'ok');

    $this->withHeader('referer', 'https://evil.example/steal')
        ->post('/__csrf-probe', [])
        ->assertStatus(419);

    expect(session('url.intended'))->toBeNull();
});

it('answers an XHR with a sentence and a 419, not with a page', function () {
    app()['env'] = 'local';

    Route::middleware('web')->post('/__csrf-probe', fn () => 'ok');

    $response = $this->postJson('/__csrf-probe', []);

    $response->assertStatus(419);
    expect($response->json('message'))->toContain('session expired');
});

/*
|--------------------------------------------------------------------------
| 503 renders with everything down
|--------------------------------------------------------------------------
*/

it('renders 503 without touching the database', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $html = hitting(503)->getContent();

    expect($queries)->toBe([], 'the 503 page ran a query; it has to render with the database down')
        ->and($html)->toContain('down for a few minutes');
});

it('renders 503 without the Vite manifest or Inertia', function () {
    /*
     * During a deploy the manifest is mid-replacement, which is exactly when a
     * 503 is being served. `@vite()` on an error page is a page that 500s at
     * the one moment it is the only page left.
     */
    $html = hitting(503)->getContent();

    expect($html)
        ->not->toContain('/build/assets/')
        ->not->toContain('data-page=')
        ->not->toContain('<link rel="stylesheet"')
        ->not->toContain('<script');
});

it('offers no links on 503, because every one of them would 503 too', function () {
    expect(hitting(503)->getContent())->not->toContain('class="ways"');
});

/*
|--------------------------------------------------------------------------
| The palette is the real one
|--------------------------------------------------------------------------
|
| The error pages inline their CSS because they cannot depend on a build
| artefact. That is the same hazard as the mockups' copied token block, so the
| values are read from tokens.css rather than typed twice.
|
*/

it('inlines the real tokens rather than a second copy of the palette', function () {
    $html = hitting(404)->getContent();

    expect(DesignTokens::value('paper'))->not->toBe('')
        ->and($html)->toContain('--paper: '.DesignTokens::value('paper'))
        ->and($html)->toContain('--ink: '.DesignTokens::value('ink'));
});

it('does not leak a stack trace or an exception class', function () {
    $html = hitting(500)->getContent();

    expect($html)
        ->not->toContain('vendor/laravel')
        ->not->toContain('Exception')
        ->not->toContain('#0 ');
});
