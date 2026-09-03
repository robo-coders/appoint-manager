<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;
use App\Support\MarketingFigures;
use Illuminate\Support\Facades\Log;

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
    '/how-it-works',
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

    expect($html)->toContain(route('marketing.pricing'))
        ->and($html)->toContain(route('marketing.dog-grooming'));
})->with(MARKETING_PATHS);

it('puts how it works and pricing in the masthead, and the trade page in the footer', function () {
    $html = $this->get('/')->assertOk()->getContent();
    $header = substr($html, strpos($html, '<header'), strpos($html, '</header>') - strpos($html, '<header'));
    $footer = substr($html, strpos($html, '<footer'), strpos($html, '</footer>') - strpos($html, '<footer'));

    expect($header)->toContain(route('marketing.pricing'))
        ->and($header)->toContain(route('marketing.how-it-works'))
        ->and($header)->toContain('Pricing')
        ->and($header)->toContain('How it works')
        ->and($header)->not->toContain('off-phone')
        ->and($footer)->toContain(route('marketing.dog-grooming'))
        ->and($footer)->toContain('Dog grooming');
});

it('does not invent a salon name on the quoted waitlist texts', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)
        ->toContain("the salon's name")
        ->not->toContain('Willow Street')
        ->not->toContain('Willow Street Grooming');
})->with(['/dog-grooming', '/how-it-works']);

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
    // The caches, the CSP and these tests all key off it, and `app.blade.php`
    // asks the same question on the other surfaces. A page without it is a page
    // no other layer can identify.
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

    expect($figures->monthlyBare())->toBe('£29')
        ->and($figures->monthly()->formatted())->toBe('£29.00')
        ->and($figures->trialDays())->toBe(30);

    $html = $this->get('/pricing')->assertOk()->getContent();

    expect($html)->toContain($figures->monthlyBare())
        ->and($html)->toContain((string) $figures->trialDays());
});

it('builds the refill sum from the seeded price list and subtracts it', function () {
    $figures = new MarketingFigures;
    $groomer = $figures->vertical('groomer');

    // The slot price is the groomer vertical's own medium full groom, read from
    // the `verticals` table, not a literal. It is the longest appointment on
    // the list, which is how the class picks it.
    expect($groomer->slotName())->toBe('Full groom — medium dog')
        ->and($groomer->slot()->formatted())->toBe('£45.00')
        ->and($groomer->deposit()->formatted())->toBe('£10.00')
        ->and($groomer->slotMinutes())->toBe(90);

    // Derived, so a price change cannot leave a stale total on the page.
    expect($groomer->surplus()->amount)->toBe($groomer->slot()->amount - $figures->monthly()->amount)
        ->and($groomer->oneRefillCovers())->toBeTrue();

    $html = $this->get('/dog-grooming')->assertOk()->getContent();

    expect($html)->toContain($groomer->slot()->formatted())
        ->and($html)->toContain($groomer->surplus()->formatted());
});

/**
 * The trade page reads its words from the vertical, not from the template.
 *
 * This is the assertion behind "a second trade page is a copy file, not a
 * rebuild": the same `VerticalFigures` asked for `barber` — a vertical that has
 * a row and no page — answers with that trade's own label, subject noun and
 * price list, without a line of code changing.
 */
it('reads a vertical\'s words and prices from the verticals table', function () {
    // A second vertical, with nothing else changed. No page for it, no route,
    // no template: only a row. That is the whole claim being tested.
    Vertical::query()->create([
        'key' => 'barbers-test',
        'label' => 'Barbers',
        'subject_singular' => 'client',
        'subject_plural' => 'clients',
        'customer_singular' => 'client',
        'appointment_singular' => 'appointment',
        'subject_fields' => [],
        'default_services' => [
            ['name' => 'Skin fade', 'price' => 1800, 'deposit_amount' => 500, 'duration_minutes' => 30],
            ['name' => 'Cut and beard trim', 'price' => 2400, 'deposit_amount' => 500, 'duration_minutes' => 45],
        ],
    ]);

    $figures = new MarketingFigures;

    $groomer = $figures->vertical('groomer');
    $barber = $figures->vertical('barbers-test');

    expect($groomer->label())->toBe('Dog grooming')
        ->and($groomer->subject())->toBe('dog')
        ->and($groomer->priceList())->not->toBeEmpty();

    expect($barber->label())->toBe('Barbers')
        ->and($barber->subject())->toBe('client')
        ->and($barber->slotName())->toBe('Cut and beard trim')
        ->and($barber->slot()->formatted())->toBe('£24.00')
        ->and($barber->slotMinutes())->toBe(45);
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

    $html = $this->get('/dog-grooming')->assertOk()->getContent();

    expect($html)->toContain('a slot is free. Claim:')
        ->and($html)->toContain('that slot was taken. We will text if another opens.');
});

it('states the real waitlist batch size and window', function () {
    $html = $this->get('/dog-grooming')->assertOk()->getContent();

    expect($html)->toContain((string) config('booking.waitlist_offer_batch'))
        ->and($html)->toContain(config('booking.waitlist_offer_minutes').' minutes');
});

it('shows the seeded grooming price list on the trade page', function () {
    $html = $this->get('/dog-grooming')->assertOk()->getContent();

    foreach (Vertical::query()->where('key', 'groomer')->firstOrFail()->default_services as $service) {
        expect($html)->toContain($service['name']);
    }
});

it('prints both list prices from billing config', function () {
    $figures = new MarketingFigures;

    expect($figures->yearlyBare())->toBe('£290')
        ->and($figures->yearlyLabel())->toBe('2 months free');

    $html = $this->get('/pricing')->assertOk()->getContent();

    expect($html)->toContain($figures->monthlyBare())
        ->and($html)->toContain($figures->yearlyBare())
        ->and($html)->toContain($figures->yearlyLabel())
        ->and($html)->toContain((string) $figures->smsIncluded())
        ->and($html)->toContain($figures->smsTopupBare());
});

/**
 * No competitor is named anywhere, on any page, including the one that compares
 * against one.
 *
 * The pricing table used to name a product. It was accurate on the day it was
 * written, kept accurate by nobody, and it was somebody else's price list
 * published on our site. The comparison is by shape of charge now — a free tier
 * that bills your customer, against one price that bills you — which is the
 * actual argument and needs no name attached.
 */
it('names no competitor on any page', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    foreach (['Tuft', 'Treatwell', 'Fresha', 'Booksy', 'Timely', 'Vagaro', 'Kitomba', 'Phorest'] as $name) {
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

it('does not sell a from-price or a second tier', function () {
    $html = $this->get('/pricing')->assertOk()->getContent();

    foreach (['from £29', 'from £', 'Essential plan', 'Pro plan'] as $banned) {
        expect($html)->not->toContain($banned);
    }
});

/*
|--------------------------------------------------------------------------
| One header and one footer, on every page
|--------------------------------------------------------------------------
|
| The two shared components are the ones that rot: a page gets a copy, the copy
| gets a link the original does not have, and nobody notices until a visitor
| finds a nav that changes shape as they move around. So the bytes are compared
| rather than the links.
|
*/

/** Cut one element out of a page by tag name. */
function regionOf(string $html, string $tag): string
{
    $open = strpos($html, '<'.$tag);
    $close = strpos($html, '</'.$tag.'>');

    expect($open)->not->toBeFalse("the page has no <{$tag}>");

    return substr($html, $open, $close - $open);
}

it('renders a byte-identical header and footer on every page', function () {
    $headers = [];
    $footers = [];

    foreach (MARKETING_PATHS as $path) {
        $html = $this->get($path)->assertOk()->getContent();

        $headers[$path] = regionOf($html, 'header');
        $footers[$path] = regionOf($html, 'footer');
    }

    /*
     * The `active` class on the current nav link is the one legitimate
     * difference, and it is exactly what a shared component should vary by. It
     * is stripped rather than exempted so everything else still has to match.
     */
    $normalise = fn (string $region) => str_replace([' class="active"', ' class=""'], '', $region);

    $firstHeader = $normalise(reset($headers));
    $firstFooter = reset($footers);

    foreach (MARKETING_PATHS as $path) {
        expect($normalise($headers[$path]))->toBe($firstHeader, "the header on {$path} has drifted");
        expect($footers[$path])->toBe($firstFooter, "the footer on {$path} has drifted");
    }
});

it('leaves the logo slot empty and marked rather than faking a mark', function () {
    $footer = regionOf($this->get('/')->getContent(), 'footer');

    // There is no logo asset. A placeholder that looks like one gets shipped.
    expect($footer)->toContain('logo-slot')
        ->and($footer)->not->toContain('<img')
        ->and($footer)->not->toContain('<svg');
});

it('carries the legal links, the contact address and a copyright line in the footer', function (string $path) {
    $footer = regionOf($this->get($path)->assertOk()->getContent(), 'footer');

    expect($footer)->toContain(route('marketing.privacy'))
        ->and($footer)->toContain(route('marketing.terms'))
        ->and($footer)->toContain(route('marketing.contact'))
        ->and($footer)->toContain('mailto:')
        ->and($footer)->toContain('&copy;')
        ->and($footer)->toContain((string) now()->year);
})->with(MARKETING_PATHS);

/*
|--------------------------------------------------------------------------
| Vertical containment
|--------------------------------------------------------------------------
*/

/**
 * No shared page names a trade.
 *
 * The product is multi-vertical and the marketing site has one vertical live.
 * Every page except that one has to still read true when the second arrives, so
 * "groom" may not appear in any of them — not in the copy, and not in the
 * header or footer that every page carries.
 *
 * /about is the one page that has to say which trade went first, and it does it
 * by reading the label out of the `verticals` table rather than by spelling it,
 * so the rendered word is allowed there while a hardcoded one still is not.
 * That is asserted separately below.
 */
it('names no trade on any page but the trade page', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    foreach (['groomer', 'grooming salon', 'the dog', 'a dog', 'puppy', 'breed'] as $word) {
        expect(strtolower($html))->not->toContain(strtolower($word), "{$path} names a trade");
    }
})->with(array_values(array_diff(MARKETING_PATHS, ['/dog-grooming', '/about'])));

it('keeps the trade page the only place a trade is written down', function () {
    $shared = [
        resource_path('views/marketing/layout.blade.php'),
        resource_path('views/marketing/partials/nav.blade.php'),
        resource_path('views/marketing/partials/footer.blade.php'),
        resource_path('views/marketing/partials/cta-band.blade.php'),
        resource_path('views/marketing/partials/vertical-page.blade.php'),
        resource_path('views/marketing/home.blade.php'),
        resource_path('views/marketing/pricing.blade.php'),
        resource_path('views/marketing/how-it-works.blade.php'),
        resource_path('views/marketing/about.blade.php'),
        resource_path('views/marketing/contact.blade.php'),
        resource_path('views/marketing/privacy.blade.php'),
        resource_path('views/marketing/terms.blade.php'),
        resource_path('views/marketing/not-found.blade.php'),
    ];

    foreach ($shared as $file) {
        // Blade comments are documentation and explain *why* the rule exists,
        // so they have to be able to name the thing. Only what renders counts.
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file));

        expect(strtolower((string) $source))
            ->not->toContain('groom', basename($file).' spells a trade out');
    }
});

it('shows the trade page label from the verticals table rather than a literal', function () {
    $label = Vertical::query()->where('key', 'groomer')->firstOrFail()->label;

    $footer = regionOf($this->get('/')->getContent(), 'footer');

    expect($footer)->toContain($label);

    // And /about names it the same way, by reading it.
    expect($this->get('/about')->getContent())->toContain(strtolower($label));
});

/*
|--------------------------------------------------------------------------
| Prices come from config, everywhere
|--------------------------------------------------------------------------
*/

it('prints no price that is not in config/billing.php', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    // Strip the one place a third-party figure is allowed, which carries an
    // UNVERIFIED marker in the template next to it.
    $html = (string) preg_replace('/<td class="them">.*?<\/td>/s', '', $html);

    preg_match_all('/£\s?([\d,]+)(?:\.(\d{2}))?/u', $html, $matches, PREG_SET_ORDER);

    $monthly = (int) config('billing.monthly_price_pence');

    $allowed = [
        $monthly,
        (int) config('billing.yearly_price_pence'),
        (int) config('billing.sms_topup_price_pence'),
        /*
         * The yearly price shown per month. Rounded to whole pounds exactly the
         * way `MarketingFigures::yearlyPerMonthBare()` rounds it, so this
         * follows that method rather than approximating it.
         */
        intdiv(intdiv((int) config('billing.yearly_price_pence'), 12), 100) * 100,
    ];

    foreach (Vertical::query()->get() as $vertical) {
        foreach ((array) $vertical->default_services as $service) {
            $allowed[] = (int) $service['price'];
            $allowed[] = (int) $service['deposit_amount'];
            // The refill sum: one appointment, less one month of software.
            $allowed[] = max(0, (int) $service['price'] - $monthly);
        }
    }

    foreach ($matches as $match) {
        $pence = ((int) str_replace(',', '', $match[1])) * 100 + (int) ($match[2] ?? 0);

        // `toContain` takes needles as varargs, so a message passed as a second
        // argument is silently asserted as a second needle. Hence the boolean.
        expect(in_array($pence, $allowed, true))->toBeTrue(
            "{$path} prints {$match[0]}, which is not a figure from config/billing.php or a seeded price",
        );
    }
})->with(MARKETING_PATHS);

/*
|--------------------------------------------------------------------------
| The contact form
|--------------------------------------------------------------------------
*/

it('serves the contact page outside the public cache group', function () {
    // It carries a CSRF token. A shared cache handing one visitor's token to
    // another is a 419 on submit for everybody but the first person through.
    $response = $this->get('/contact')->assertOk();

    expect($response->headers->get('Cache-Control'))->not->toContain('public');
});

it('accepts a contact enquiry and says so', function () {
    Log::spy();

    $this->post('/contact', [
        'name' => 'Jo Kerr',
        'business' => 'Kerr and Co',
        'email' => 'jo@example.test',
        'phone' => '07700 900123',
        'message' => 'Can I move my existing diary across before the trial ends?',
    ])
        ->assertRedirect(route('marketing.contact'))
        ->assertSessionHas('contact.sent');

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'Marketing enquiry'
            && $context['email'] === 'jo@example.test');
});

it('rejects an enquiry with nothing in it', function () {
    $this->post('/contact', [])
        ->assertSessionHasErrors(['name', 'business', 'email', 'message']);
});

it('drops anything that fills the honeypot', function () {
    $this->post('/contact', [
        'name' => 'Bot',
        'business' => 'Bot',
        'email' => 'bot@example.test',
        'message' => 'Buy my search engine optimisation package today please.',
        'company_website' => 'http://example.test',
    ])->assertSessionHasErrors('company_website');
});

it('throttles a flood of enquiries from one address', function () {
    $payload = [
        'name' => 'Jo Kerr',
        'business' => 'Kerr and Co',
        'email' => 'jo@example.test',
        'message' => 'A question about deposits and how they reach my account.',
    ];

    for ($i = 0; $i < 5; $i++) {
        $this->post('/contact', $payload)->assertSessionHas('contact.sent');
    }

    $this->post('/contact', $payload)->assertSessionHasErrors('message');
});

/*
|--------------------------------------------------------------------------
| The 404
|--------------------------------------------------------------------------
*/

/**
 * A 404 on the apex domain is the marketing site, not the framework's page.
 *
 * `Surface::current` can only tell the surfaces apart when routing by subdomain,
 * which is how production runs and is not how the suite runs, so the two config
 * keys are set here. The routes were registered without host constraints at
 * boot and stay that way; the handler reads the config at request time, which is
 * the whole of what is under test.
 */
it('renders the marketing 404 on the marketing host', function () {
    config([
        'app.subdomain_routing' => true,
        'app.surfaces.marketing' => 'http://diarydesk.test',
        'app.surfaces.app' => 'http://app.diarydesk.test',
    ]);

    $html = $this->get('http://diarydesk.test/no-such-page')
        ->assertNotFound()
        ->getContent();

    expect($html)->toContain('data-page="not-found"')
        ->and($html)->toContain('There is nothing at this address')
        ->and($html)->toContain('name="robots" content="noindex"')
        // The shared chrome, so a stranger who lands here can leave through it.
        ->and($html)->toContain(route('marketing.pricing'))
        ->and($html)->toContain('<footer');
});

it('leaves the other surfaces on the plain error page', function () {
    config([
        'app.subdomain_routing' => true,
        'app.surfaces.marketing' => 'http://diarydesk.test',
        'app.surfaces.app' => 'http://app.diarydesk.test',
    ]);

    $html = $this->get('http://app.diarydesk.test/no-such-page')
        ->assertNotFound()
        ->getContent();

    // No @vite, no marketing shell: that page renders when the build may not be
    // there, and it must keep doing so.
    expect($html)->not->toContain('data-page="not-found"')
        ->and($html)->toContain('404 Not found');
});
