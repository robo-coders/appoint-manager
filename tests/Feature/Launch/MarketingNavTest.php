<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\MarketingFigures;

/**
 * The marketing surface's navigation, and the claims its pages make.
 *
 * "A 404 from the marketing nav is the one bug I will find by accident in front
 * of a customer" — so this walks every route, and then every link inside the
 * masthead and the footer of every page, and asks the application for each one.
 * A link is only tested if it is followed.
 */
/** Every page on the surface, including the two that are not HTML. */
const MARKETING_PATHS = [
    '/',
    '/pricing',
    '/dog-grooming',
    '/about',
    '/contact',
    '/privacy',
    '/terms',
];

/** Pull the hrefs out of one element of the page. */
function linksInside(string $html, string $tag): array
{
    $open = strpos($html, '<'.$tag);
    $close = strpos($html, '</'.$tag.'>');

    expect($open)->not->toBeFalse("the page has no <{$tag}>");
    expect($close)->not->toBeFalse("the page has no </{$tag}>");

    $region = substr($html, $open, $close - $open);

    preg_match_all('/href="([^"]+)"/', $region, $matches);

    return array_values(array_unique($matches[1]));
}

it('serves every marketing route', function (string $path) {
    $this->get($path)->assertOk();
})->with(MARKETING_PATHS);

it('serves the sitemap and robots', function () {
    $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml');
    $this->get('/robots.txt')->assertOk();

    // Every page is in the sitemap, so a new page cannot be added without being
    // findable. The old sitemap listed exactly these and nothing checked it.
    $xml = $this->get('/sitemap.xml')->getContent();

    foreach (MARKETING_PATHS as $path) {
        expect($xml)->toContain(url($path));
    }
});

/**
 * The actual crawl. Every link in the masthead and the footer, on every page,
 * fetched. Cross-surface links (`/login`, `/register`) are included because
 * under path routing they are on this host, and they are exactly the two links
 * a returning owner and a new one press.
 */
it('has no dead link in the masthead or footer of any page', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    $links = [...linksInside($html, 'header'), ...linksInside($html, 'footer')];

    expect($links)->not->toBeEmpty();

    foreach ($links as $href) {
        // Skip what is not ours to serve.
        if (str_starts_with($href, 'mailto:') || str_starts_with($href, '#')) {
            continue;
        }

        $target = str_starts_with($href, 'http') ? parse_url($href, PHP_URL_PATH) ?: '/' : $href;

        $status = $this->get($target)->getStatusCode();

        expect($status)->toBeLessThan(400, "{$path} links {$href} ({$target}) which returned {$status}");
    }
})->with(MARKETING_PATHS);

it('offers a way in from every marketing page', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)->toContain(route('login'))
        ->and($html)->toContain('Log in');
})->with(MARKETING_PATHS);

it('reaches pricing and the trade page from every page', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    // Both are hidden in the masthead below 768 and live in the footer at every
    // width, so "present somewhere on the page" is the honest assertion.
    expect($html)->toContain(route('marketing.pricing'))
        ->and($html)->toContain(route('marketing.dog-grooming'));
})->with(MARKETING_PATHS);

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

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $authed = $this->actingAs($user)->get('/')->getContent();

    expect($authed)->toBe($guest);
});

it('sets the surface on the root of every page', function (string $path) {
    // `--page`, `--gutter` and `--arg` are gated on this attribute in
    // tokens.css. Without it the page has no frame at all and the failure is a
    // full-bleed layout, not an error.
    $this->get($path)->assertOk()->assertSee('data-surface="marketing"', false);
})->with(MARKETING_PATHS);

it('puts a skip link first inside the body of every page', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)->toContain('class="skip-link" href="#main"')
        ->and($html)->toContain('id="main"');

    // Before the masthead, or it is not a skip link.
    expect(strpos($html, 'skip-link'))->toBeLessThan(strpos($html, '<header'));
})->with(MARKETING_PATHS);

/*
|--------------------------------------------------------------------------
| The claims on the page
|--------------------------------------------------------------------------
|
| Every figure is either traceable to this repository or carries an inline
| UNVERIFIED marker. These are the tests that keep that true as the config
| moves, rather than a promise that it was true on the day it was written.
|
*/

it('prints the price the product actually charges', function () {
    $figures = new MarketingFigures;

    expect($figures->monthlyBare())->toBe('£39')
        ->and($figures->monthly()->formatted())->toBe('£39.00')
        ->and($figures->trialDays())->toBe(30);

    $html = $this->get('/pricing')->assertOk()->getContent();

    expect($html)->toContain($figures->monthlyBare())
        ->and($html)->toContain((string) $figures->trialDays());
});

it('builds the refill sum from the seeded price list and subtracts it', function () {
    $figures = new MarketingFigures;

    // The slot price is config/verticals.php's medium full groom, not a literal.
    expect($figures->slot()->formatted())->toBe('£45.00')
        ->and($figures->deposit()->formatted())->toBe('£10.00')
        ->and($figures->slotMinutes())->toBe(90);

    // Derived, so a price change cannot leave a stale total on the page.
    expect($figures->surplus()->amount)->toBe($figures->slot()->amount - $figures->monthly()->amount)
        ->and($figures->oneRefillCovers())->toBeTrue();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain($figures->slot()->formatted())
        ->and($html)->toContain($figures->surplus()->formatted());
});

it('does not carry the old three-no-shows arithmetic anywhere', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    // "A £45 slot. Three no-shows a week is £135 gone" was on the home page and
    // the trade page. The sums on this surface are derived from stated inputs,
    // never a slot price multiplied by an invented number of missed
    // appointments.
    foreach (['£135', '£165', '£660', '£90 and a wasted', '£585'] as $ghost) {
        expect($html)->not->toContain($ghost);
    }
})->with(MARKETING_PATHS);

it('quotes the waitlist texts exactly as the Notifier sends them', function () {
    // The page prints these two bodies as quotations. If somebody rewrites the
    // messages, this fails here rather than leaving the page quietly wrong.
    $source = file_get_contents(app_path('Services/Notifications/Notifier.php'));

    expect($source)->toContain("': a slot is free. Claim: '")
        ->and($source)->toContain("': that slot was taken. We will text if another opens.'");

    foreach (['/', '/dog-grooming'] as $path) {
        $html = $this->get($path)->assertOk()->getContent();

        expect($html)->toContain('a slot is free. Claim:')
            ->and($html)->toContain('that slot was taken. We will text if another opens.');
    }
});

it('states the real waitlist batch size and window', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain((string) config('booking.waitlist_offer_batch'))
        ->and($html)->toContain(config('booking.waitlist_offer_minutes').' minutes');
});

it('shows the seeded grooming price list on the trade page', function () {
    $html = $this->get('/dog-grooming')->assertOk()->getContent();

    foreach (config('verticals.groomer.default_services') as $service) {
        expect($html)->toContain($service['name']);
    }
});

it('marks the one unverified figure and does not name the competitor', function () {
    $html = $this->get('/pricing')->assertOk()->getContent();

    // The marker ships in the HTML, on the page, next to the figure.
    expect($html)->toContain('<!-- UNVERIFIED: competitor per-booking fee, rate and plan scope not confirmed -->')
        ->and($html)->toContain('£1.25');

    // The marker is immediately before the figure, not filed at the top of the
    // file where nobody reading the figure would see it.
    //
    // Asserted as "nothing but comments and whitespace in between" rather than a
    // character count. A count is a number somebody has to keep raising: adding
    // the second comment — the one naming what would verify the figure — took
    // the gap from 90 characters to 366 and failed a `< 200` bound that was
    // never really about distance. What it was about is that no markup, and in
    // particular no other table cell, gets between the caveat and the number it
    // is caveating.
    $marker = strpos($html, '<!-- UNVERIFIED: competitor per-booking fee');
    $figure = strpos($html, '£1.25');

    expect($marker)->toBeLessThan($figure);

    $between = substr($html, $marker, $figure - $marker);

    // Strip the comments, and what is left should be whitespace and the one
    // opening `<td>` the figure sits in.
    $bare = trim(preg_replace(['/<!--.*?-->/s', '/<td[^>]*>/'], '', $between));

    expect($bare)->toBe('', "markup sits between the UNVERIFIED marker and £1.25: {$bare}");
});

it('names no competitor anywhere on the surface', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    // The rejected direction named one in its copy and its FAQ.
    foreach (['Tuft', 'Treatwell', 'Fresha', 'Booksy', 'Timely', 'Vagaro'] as $name) {
        expect($html)->not->toContain($name);
    }
})->with(MARKETING_PATHS);

it('invents no social proof on any page', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    foreach (['Trusted by', 'trusted by', 'salons already', 'customers already', '★', 'Rated ', 'As featured in'] as $banned) {
        expect($html)->not->toContain($banned);
    }
})->with(MARKETING_PATHS);

it('draws no interface it does not own, and no test card', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    // A previous run rendered a fake payment form containing Stripe's 4242 test
    // card. That is somebody else's component and a developer's fingerprint.
    foreach (['4242', 'card-number', 'cardnumber', 'MM / YY', 'CVC'] as $banned) {
        expect($html)->not->toContain($banned);
    }
})->with(MARKETING_PATHS);

it('sells one price and no annual plan', function () {
    $html = $this->get('/pricing')->assertOk()->getContent();

    // The yearly plan is no longer in config. Marketing must not sell one, and is deliberately not
    // offered here. See DECISIONS.md.
    foreach (['£390', 'a year', 'yearly', 'Yearly', 'annual', 'Annually', 'two months free', 'from £39'] as $banned) {
        expect($html)->not->toContain($banned);
    }
});
