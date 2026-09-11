<?php

use App\Support\DesignTokens;
use App\Support\ErrorPage;
use App\Support\Surface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function hitting(int $code, string $path = '/__error-probe')
{
    Route::middleware('web')->get($path, fn () => abort($code));

    return test()->get($path);
}

function isOurs(string $html): bool
{
    return str_contains($html, 'class="eyebrow"') && str_contains($html, '--paper:');
}

beforeEach(fn () => config(['app.subdomain_routing' => false, 'app.debug' => false]));

it('renders our own page for every status', function (int $code) {
    $response = hitting($code);

    $response->assertStatus($code);

    $html = $response->getContent();

    expect(isOurs($html))->toBeTrue('status '.$code.' fell through to a stock error page')
        ->and($html)->toContain((string) $code)
        ->and($html)->toContain(config('product.name'));
})->with([403, 404, 419, 429, 500, 503]);

it('renders our 404 for a URL that simply does not exist', function () {
    $response = $this->get('/no-such-operator-page');

    $response->assertNotFound();

    expect(isOurs($response->getContent()))->toBeTrue()
        ->and($response->getContent())->toContain('nothing at this address');
});

it('tells a customer on the booking host that a salon link is wrong', function () {
    $html = $this->get('/book/no-such-salon')->getContent();

    expect($html)
        ->toContain('booking link')
        ->toContain('salon')
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

it('offers a way back rather than a dead end', function () {
    $html = hitting(419)->getContent();

    expect($html)
        ->toContain('Sign in and carry on')
        ->toContain('back where you were')
        ->toContain('signed out while that page was open');
});

it('stores the page you were on, so signing in returns you to it', function () {
    app()['env'] = 'local';

    $was = config('app.url').'/diary?date=2026-08-26';

    Route::middleware('web')->post('/__csrf-probe', fn () => 'ok');

    $response = $this->withHeader('referer', $was)->post('/__csrf-probe', []);

    $response->assertStatus(419);

    expect(session('url.intended'))->toBe($was)
        ->and($response->getContent())->toContain('/diary');
});

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
