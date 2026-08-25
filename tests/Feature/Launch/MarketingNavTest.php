<?php

/**
 * A returning salon owner arrives at the front page. Until now there was no way
 * in from there at all — only "Start free trial", which is the wrong door.
 */
it('offers a way in from every marketing page', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)->toContain(route('login'))
        ->and($html)->toContain('Log in');
})->with(['/', '/pricing', '/dog-grooming', '/about', '/contact', '/privacy', '/terms']);

it('keeps the trial the louder of the two', function () {
    $html = $this->get('/')->assertOk()->getContent();

    $header = substr($html, strpos($html, '<header'), strpos($html, '</header>') - strpos($html, '<header'));

    // Both present, and the trial link comes last so it reads as the primary door.
    expect($header)->toContain('Log in')
        ->and($header)->toContain('Start free trial')
        ->and(strpos($header, 'Log in'))->toBeLessThan(strpos($header, 'Start free trial'));
});

it('does not vary the marketing header by who is logged in', function () {
    // These pages are served with `cache.headers:public`, so a shared cache may
    // hand one visitor's HTML to another. The header must not depend on session.
    $guest = $this->get('/')->getContent();

    $tenant = App\Models\Tenant::factory()->create();
    $user = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $authed = $this->actingAs($user)->get('/')->getContent();

    expect($authed)->toBe($guest);
});
