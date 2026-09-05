<?php

use App\Mail\MarketingEnquiryMail;
use App\Support\MarketingFaq;
use App\Support\MarketingFigures;
use Illuminate\Support\Facades\Mail;

/**
 * Whether the marketing site can be found, read and quoted correctly.
 *
 * `MarketingNavTest` walks the links and asserts the copy's claims. This asserts
 * the machine-readable half: the heading structure, the landmarks, the per-page
 * metadata, the JSON-LD, and the three files a crawler or an answer engine asks
 * for by name.
 *
 * The two rules worth stating, because they are the ones a future change breaks
 * silently:
 *
 *   1. **The sitemap and `llms.txt` are read off the router.** A page added to
 *      `routes/marketing.php` and to nothing else must appear in both. That is
 *      the one assertion here that fails on a *new* page rather than a changed
 *      one, which is the point.
 *   2. **The `FAQPage` markup must match the visible text.** They come out of
 *      one array (`MarketingFaq`); this proves it, because the alternative is
 *      being quoted saying something the page does not say.
 */

/** Every page on the surface. */
const DISCOVERY_PATHS = [
    '/',
    '/pricing',
    '/how-it-works',
    '/dog-grooming',
    '/about',
    '/contact',
    '/privacy',
    '/terms',
];

/**
 * The JSON-LD graph on a page, decoded.
 *
 * @return array<string, mixed>
 */
function graphOn(string $html): array
{
    expect(preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m))
        ->toBe(1, 'the page has no JSON-LD block');

    $decoded = json_decode($m[1], true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE, 'the JSON-LD block is not valid JSON: '.json_last_error_msg());

    return $decoded;
}

/**
 * The `@type`s present in a page's graph. A node may declare several.
 *
 * @return list<string>
 */
function typesIn(array $graph): array
{
    $types = [];

    foreach ($graph['@graph'] ?? [] as $node) {
        foreach ((array) ($node['@type'] ?? []) as $type) {
            $types[] = $type;
        }
    }

    return $types;
}

/*
|--------------------------------------------------------------------------
| Semantic HTML
|--------------------------------------------------------------------------
*/

it('gives every page exactly one h1', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    expect(preg_match_all('/<h1\b/', $html))->toBe(1);
})->with(DISCOVERY_PATHS);

it('never skips a heading level', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    preg_match_all('/<h([1-6])\b/', $html, $matches);
    $levels = array_map('intval', $matches[1]);

    expect($levels)->not->toBeEmpty();

    // An h1 followed by an h3 is a document with a level missing out of the
    // middle of it, which is what a screen reader's heading list reads as a gap.
    foreach (array_slice($levels, 1) as $index => $level) {
        expect($level)->toBeLessThanOrEqual(
            $levels[$index] + 1,
            "heading level jumped from h{$levels[$index]} to h{$level} on {$path}",
        );
    }
})->with(DISCOVERY_PATHS);

it('gives every page the landmark elements', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)
        ->toContain('<main id="main">')
        ->toContain('<footer')
        ->toContain('<header')
        // Two: the masthead's and the footer's, each with its own accessible
        // name, because two unnamed navigations are indistinguishable in a
        // landmark list.
        ->toContain('aria-label="Main"')
        ->toContain('aria-label="Footer"');
})->with(DISCOVERY_PATHS);

/*
|--------------------------------------------------------------------------
| Metadata
|--------------------------------------------------------------------------
*/

it('gives every page a unique, specific title and description', function () {
    $titles = [];
    $descriptions = [];

    foreach (DISCOVERY_PATHS as $path) {
        $html = $this->get($path)->assertOk()->getContent();

        expect(preg_match('~<title>(.*?)</title>~s', $html, $title))->toBe(1, "no title on {$path}");
        expect(preg_match('~<meta name="description" content="([^"]*)"~', $html, $description))
            ->toBe(1, "no description on {$path}");

        // Long enough to be a sentence about this page rather than a label, and
        // inside what a search result will actually print.
        expect(strlen($description[1]))->toBeGreaterThan(70)->toBeLessThan(320);

        $titles[$path] = $title[1];
        $descriptions[$path] = $description[1];
    }

    expect(array_unique($titles))->toHaveCount(count(DISCOVERY_PATHS));
    expect(array_unique($descriptions))->toHaveCount(count(DISCOVERY_PATHS));
});

it('gives every page a canonical url pointing at itself', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.url($path).'">');
})->with(DISCOVERY_PATHS);

/*
|--------------------------------------------------------------------------
| Structured data
|--------------------------------------------------------------------------
*/

it('puts a valid organisation graph on every page', function (string $path) {
    $graph = graphOn($this->get($path)->assertOk()->getContent());

    expect($graph['@context'])->toBe('https://schema.org')
        ->and(typesIn($graph))->toContain('Organization');
})->with(DISCOVERY_PATHS);

it('puts LocalBusiness on the home page and contact, and nowhere else', function () {
    foreach (DISCOVERY_PATHS as $path) {
        $types = typesIn(graphOn($this->get($path)->getContent()));
        $expected = in_array($path, ['/', '/contact'], true);

        expect(in_array('LocalBusiness', $types, true))->toBe($expected, "LocalBusiness on {$path}");
    }
});

it('puts Service on the trade page, and nowhere else', function () {
    foreach (DISCOVERY_PATHS as $path) {
        $types = typesIn(graphOn($this->get($path)->getContent()));

        expect(in_array('Service', $types, true))->toBe($path === '/dog-grooming', "Service on {$path}");
    }
});

it('prices the offer from config rather than from prose', function () {
    $graph = graphOn($this->get('/pricing')->getContent());
    $offer = $graph['@graph'][0]['offers'];

    expect($offer['lowPrice'])->toBe(number_format(config('billing.monthly_price_pence') / 100, 2, '.', ''))
        ->and($offer['highPrice'])->toBe(number_format(config('billing.yearly_price_pence') / 100, 2, '.', ''))
        ->and($offer['priceCurrency'])->toBe('GBP');
});

it('states no street address and no telephone it does not have', function () {
    $graph = graphOn($this->get('/contact')->getContent());
    $business = collect($graph['@graph'])->firstWhere('@type', 'LocalBusiness');

    expect($business['address'])->not->toHaveKey('streetAddress')
        ->and($business)->not->toHaveKey('telephone')
        ->and($business['address']['addressLocality'])->toBe(config('marketing.locality'));
});

/*
|--------------------------------------------------------------------------
| FAQ, once
|--------------------------------------------------------------------------
*/

it('publishes an FAQ on the home page and pricing, matching the visible text', function (string $path, string $method) {
    $html = $this->get($path)->assertOk()->getContent();
    $graph = graphOn($html);
    $faqPage = collect($graph['@graph'])->firstWhere('@type', 'FAQPage');
    $expected = app(MarketingFaq::class)->{$method}();

    expect($expected)->toHaveCount(count($faqPage['mainEntity']))
        ->and(count($expected))->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(8);

    foreach ($expected as $index => $item) {
        // Visible, as a heading with its answer immediately after it.
        expect($html)
            ->toContain('<h3 class="question">'.e($item['question']).'</h3>')
            ->toContain($item['answer']);

        // And the same words in the markup, which is what the policy requires.
        expect($faqPage['mainEntity'][$index]['name'])->toBe($item['question'])
            ->and($faqPage['mainEntity'][$index]['acceptedAnswer']['text'])->toBe($item['answer']);
    }
})->with([
    ['/', 'home'],
    ['/pricing', 'pricing'],
]);

it('puts no FAQ markup on a page with no questions on it', function () {
    foreach (['/how-it-works', '/about', '/privacy', '/terms', '/dog-grooming', '/contact'] as $path) {
        expect(typesIn(graphOn($this->get($path)->getContent())))->not->toContain('FAQPage');
    }
});

/*
|--------------------------------------------------------------------------
| The three machine files
|--------------------------------------------------------------------------
*/

it('lists every marketing page in the sitemap, read off the router', function () {
    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    foreach (DISCOVERY_PATHS as $path) {
        expect($xml)->toContain('<loc>'.url($path).'</loc>');
    }

    // And nothing that is not a page. A sitemap that lists itself, or lists the
    // POST target of the contact form, is a sitemap nobody has read.
    expect($xml)->not->toContain('sitemap.xml</loc>')
        ->not->toContain('robots.txt</loc>')
        ->not->toContain('llms.txt</loc>')
        ->and(substr_count($xml, '<loc>'))->toBe(count(DISCOVERY_PATHS));
});

it('allows crawling on the marketing host and names the sitemap', function () {
    $response = $this->get('/robots.txt')->assertOk();

    expect($response->getContent())
        ->toContain('User-agent: *')
        ->toContain('Allow: /')
        ->toContain('Sitemap: '.route('marketing.sitemap'));
});

it('serves llms.txt as plain text naming the product, the price and every page', function () {
    $response = $this->get('/llms.txt')->assertOk();
    $body = $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('text/plain')
        ->and($body)
        ->toStartWith('# '.config('product.name'))
        // The convention's summary line.
        ->toContain('> '.config('product.name').' is appointment booking software')
        ->toContain(app(MarketingFigures::class)->monthlyBare())
        ->toContain((string) config('billing.trial_days'));

    foreach (DISCOVERY_PATHS as $path) {
        expect($body)->toContain('('.url($path).')');
    }
});

/*
|--------------------------------------------------------------------------
| Readable without JavaScript
|--------------------------------------------------------------------------
*/

it('renders every page fully server-side, with nothing waiting on javascript', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    /*
     * The surface mounts no Vue by construction (REBUILD.md, phase 11), so the
     * check that matters is that no page has an empty mount point for a client
     * to fill and that the whole document is in the response. A `<div id="app">`
     * or a `data-page` Inertia payload here would mean a crawler sees a shell.
     */
    expect($html)
        ->not->toContain('id="app"')
        ->not->toContain('data-page="{&quot;component&quot;')
        ->not->toContain('@vite([\'resources/js');

    // The body has closed, so nothing was streamed and abandoned mid-render.
    expect($html)->toContain('</body>')->toContain('</html>');
})->with(DISCOVERY_PATHS);

it('shows the monthly price on pricing without running the interval toggle', function () {
    // The toggle is the only script on the surface. The price it starts on has
    // to be in the HTML, or a crawler and a person with JS off both see a blank
    // where the number is.
    $html = $this->get('/pricing')->assertOk()->getContent();
    $monthly = app(MarketingFigures::class)->monthlyBare();

    expect($html)->toContain('<span class="amount" id="price-amount">'.$monthly.'</span>')
        ->toContain('<span class="period" id="price-period">/ month</span>');
});

/*
|--------------------------------------------------------------------------
| The contact form reaches a person
|--------------------------------------------------------------------------
*/

it('emails the enquiry rather than only logging it', function () {
    Mail::fake();
    config()->set('billing.owner_alert_email', 'owner@example.test');

    $this->post(route('marketing.contact.send'), [
        'name' => 'Ada Fraser',
        'business' => 'Fraser Grooming',
        'email' => 'ada@example.test',
        'phone' => '07700 900123',
        'message' => 'Does it work if I only take deposits on the long grooms?',
    ])->assertRedirect(route('marketing.contact'))->assertSessionHas('contact.sent');

    Mail::assertQueued(MarketingEnquiryMail::class, function (MarketingEnquiryMail $mail) {
        // Ours on the envelope, theirs on reply-to. Sending as the visitor's own
        // address is how a form gets the domain's mail rejected for SPF.
        expect($mail->envelope()->replyTo[0]->address)->toBe('ada@example.test')
            ->and($mail->body)->toContain('long grooms');

        return $mail->hasTo('owner@example.test');
    });
});

it('sends nothing when there is no address configured to send it to', function () {
    Mail::fake();
    config()->set('billing.owner_alert_email', null);

    $this->post(route('marketing.contact.send'), [
        'name' => 'Ada Fraser',
        'business' => 'Fraser Grooming',
        'email' => 'ada@example.test',
        'message' => 'A question about deposits and no-shows.',
    ])->assertRedirect(route('marketing.contact'));

    Mail::assertNothingQueued();
});

it('still refuses an enquiry that trips the honeypot', function () {
    Mail::fake();

    $this->post(route('marketing.contact.send'), [
        'name' => 'Bot',
        'business' => 'Bot',
        'email' => 'bot@example.test',
        'message' => 'Buy cheap things from this website today please.',
        'company_website' => 'http://example.test',
    ])->assertSessionHasErrors('company_website');

    Mail::assertNothingQueued();
});
