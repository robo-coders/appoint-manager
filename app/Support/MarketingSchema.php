<?php

namespace App\Support;

/**
 * The marketing site's structured data.
 *
 * One class, four graphs — `Organization`, `LocalBusiness`, `Service` and
 * `FAQPage` — assembled here and rendered by
 * `marketing/partials/schema.blade.php` as one `application/ld+json` block per
 * page.
 *
 * Three rules it keeps, and each one is a rule because breaking it is how
 * structured data becomes a liability rather than an asset:
 *
 *   1. **Nothing here is a figure typed by hand.** The price on the `Offer` is
 *      the price Stripe charges, read through `MarketingFigures` from
 *      `config/billing.php`. A `priceSpecification` that disagrees with the
 *      checkout is a rich result that advertises a price we do not honour.
 *
 *   2. **Nothing here is invented to fill a shape.** `LocalBusiness` wants a
 *      street address and a telephone; there is neither, so neither is emitted.
 *      See `config/marketing.php`.
 *
 *   3. **`FAQPage` is serialised from the same array the page renders.**
 *      Google's policy requires the markup to match the visible text, and the
 *      only way to guarantee that is for there to be one copy of it. See
 *      `MarketingFaq`.
 *
 * Emitted as one `@graph` rather than several sibling script tags, with `@id`s
 * so the page-level nodes can point at the organisation instead of restating
 * it.
 */
final class MarketingSchema
{
    public function __construct(private MarketingFigures $figures) {}

    /**
     * The graph for one page.
     *
     * @param  list<array{question: string, answer: string}>  $faq
     * @return array<string, mixed>
     */
    public function graph(string $page, string $url, string $title, string $description, array $faq = []): array
    {
        $nodes = [$this->organisation()];

        if (in_array($page, ['home', 'contact'], true)) {
            $nodes[] = $this->localBusiness();
        }

        if ($page === 'vertical') {
            $nodes[] = $this->service($title, $description, $url);
        }

        if ($faq !== []) {
            $nodes[] = $this->faqPage($url, $faq);
        }

        return ['@context' => 'https://schema.org', '@graph' => $nodes];
    }

    /**
     * The product itself, as a `SoftwareApplication` under an `Organization`.
     *
     * On every page, because it is the node the others reference and because
     * "who publishes this" is the question an answer engine asks first about a
     * page it is thinking of quoting.
     *
     * @return array<string, mixed>
     */
    private function organisation(): array
    {
        return [
            '@type' => ['Organization', 'SoftwareApplication'],
            '@id' => $this->id('#organisation'),
            'name' => (string) config('product.name'),
            'url' => Surface::Marketing->url(),
            'email' => $this->figures->contactEmail(),
            'foundingDate' => (string) config('marketing.founded'),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'description' => 'Appointment booking, deposits and an automatic waitlist for small '
                .'service businesses.',
            'areaServed' => ['@type' => 'Country', 'name' => (string) config('marketing.area_served')],
            'offers' => $this->offers(),
        ];
    }

    /**
     * The two subscriptions, as they are actually sold.
     *
     * `AggregateOffer` rather than two loose `Offer`s so the low and high price
     * are stated as one range: it is one product at two billing intervals, not
     * two products.
     *
     * @return array<string, mixed>
     */
    private function offers(): array
    {
        $monthly = (int) config('billing.monthly_price_pence');
        $yearly = (int) config('billing.yearly_price_pence');

        return [
            '@type' => 'AggregateOffer',
            'priceCurrency' => 'GBP',
            'lowPrice' => number_format($monthly / 100, 2, '.', ''),
            'highPrice' => number_format($yearly / 100, 2, '.', ''),
            'offerCount' => 2,
            'url' => route('marketing.pricing'),
            'availability' => 'https://schema.org/InStock',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function localBusiness(): array
    {
        return [
            '@type' => 'LocalBusiness',
            '@id' => $this->id('#business'),
            'name' => (string) config('product.name'),
            'url' => Surface::Marketing->url(),
            'email' => $this->figures->contactEmail(),
            'parentOrganization' => ['@id' => $this->id('#organisation')],
            /*
             * Locality, region and country. No `streetAddress` — see
             * `config/marketing.php` for why an incomplete address is the
             * honest one.
             */
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => (string) config('marketing.locality'),
                'addressRegion' => (string) config('marketing.region'),
                'addressCountry' => (string) config('marketing.country'),
            ],
            'areaServed' => ['@type' => 'Country', 'name' => (string) config('marketing.area_served')],
            /*
             * Google's own bands, and the only two-character one that is true of
             * a single tier under £50 a month.
             */
            'priceRange' => '££',
        ];
    }

    /**
     * A trade page's `Service`.
     *
     * The name is the vertical's label plus what the software is, not the page's
     * headline — a headline is an argument ("Saturday's cancellation, sold
     * twice") and a service name is a thing you can buy.
     *
     * @return array<string, mixed>
     */
    private function service(string $title, string $description, string $url): array
    {
        // "Dog grooming: Saturday's cancellation, sold twice" — the trade's own
        // label is everything before the colon the controller composes.
        $trade = trim((string) strtok($title, ':')) ?: $title;

        return [
            '@type' => 'Service',
            '@id' => $url.'#service',
            'name' => $trade.' booking software',
            'serviceType' => $trade.' software',
            'description' => $description,
            'url' => $url,
            'provider' => ['@id' => $this->id('#organisation')],
            'areaServed' => ['@type' => 'Country', 'name' => (string) config('marketing.area_served')],
            'audience' => ['@type' => 'BusinessAudience', 'name' => $trade.' businesses'],
            'offers' => $this->offers(),
        ];
    }

    /**
     * @param  list<array{question: string, answer: string}>  $faq
     * @return array<string, mixed>
     */
    private function faqPage(string $url, array $faq): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $url.'#faq',
            'mainEntity' => array_map(fn (array $item) => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ], $faq),
        ];
    }

    /**
     * A stable `@id` on the marketing host, so the same node referenced from
     * nine pages is one node rather than nine.
     */
    private function id(string $fragment): string
    {
        return Surface::Marketing->url().'/'.$fragment;
    }
}
