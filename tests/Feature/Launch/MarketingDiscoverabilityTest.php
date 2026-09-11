<?php

use App\Mail\MarketingEnquiryMail;
use App\Support\MarketingFaq;
use App\Support\MarketingFigures;
use Illuminate\Support\Facades\Mail;

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

/** @return array<string, mixed> */
function graphOn(string $html): array
{
    expect(preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m))
        ->toBe(1, 'the page has no JSON-LD block');

    $decoded = json_decode($m[1], true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE, 'the JSON-LD block is not valid JSON: '.json_last_error_msg());

    return $decoded;
}

/** @return list<string> */
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

it('gives every page exactly one h1', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    expect(preg_match_all('/<h1\b/', $html))->toBe(1);
})->with(DISCOVERY_PATHS);

it('never skips a heading level', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    preg_match_all('/<h([1-6])\b/', $html, $matches);
    $levels = array_map('intval', $matches[1]);

    expect($levels)->not->toBeEmpty();

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
        ->toContain('aria-label="Main"')
        ->toContain('aria-label="Footer"');
})->with(DISCOVERY_PATHS);

it('gives every page a unique, specific title and description', function () {
    $titles = [];
    $descriptions = [];

    foreach (DISCOVERY_PATHS as $path) {
        $html = $this->get($path)->assertOk()->getContent();

        expect(preg_match('~<title>(.*?)</title>~s', $html, $title))->toBe(1, "no title on {$path}");
        expect(preg_match('~<meta name="description" content="([^"]*)"~', $html, $description))
            ->toBe(1, "no description on {$path}");

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

it('publishes an FAQ on the home page and pricing, matching the visible text', function (string $path, string $method) {
    $html = $this->get($path)->assertOk()->getContent();
    $graph = graphOn($html);
    $faqPage = collect($graph['@graph'])->firstWhere('@type', 'FAQPage');
    $expected = app(MarketingFaq::class)->{$method}();

    expect($expected)->toHaveCount(count($faqPage['mainEntity']))
        ->and(count($expected))->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(8);

    foreach ($expected as $index => $item) {
        expect($html)
            ->toContain('<h3 class="question">'.e($item['question']).'</h3>')
            ->toContain($item['answer']);

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

it('lists every marketing page in the sitemap, read off the router', function () {
    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    foreach (DISCOVERY_PATHS as $path) {
        expect($xml)->toContain('<loc>'.url($path).'</loc>');
    }

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
        ->toContain('> '.config('product.name').' is appointment booking software')
        ->toContain(app(MarketingFigures::class)->monthlyBare())
        ->toContain((string) config('billing.trial_days'));

    foreach (DISCOVERY_PATHS as $path) {
        expect($body)->toContain('('.url($path).')');
    }
});

it('renders every page fully server-side, with nothing waiting on javascript', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)
        ->not->toContain('id="app"')
        ->not->toContain('data-page="{&quot;component&quot;')
        ->not->toContain('@vite([\'resources/js');

    expect($html)->toContain('</body>')->toContain('</html>');
})->with(DISCOVERY_PATHS);

it('shows the monthly price on pricing without running the interval toggle', function () {
    $html = $this->get('/pricing')->assertOk()->getContent();
    $monthly = app(MarketingFigures::class)->monthlyBare();

    expect($html)->toContain('<span class="amount" id="price-amount">'.$monthly.'</span>')
        ->toContain('<span class="period" id="price-period">/ month</span>');
});

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
