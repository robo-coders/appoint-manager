<?php

namespace App\Support;

final class MarketingSchema
{
    public function __construct(private MarketingFigures $figures) {}

    /**
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

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private function localBusiness(): array
    {
        return [
            '@type' => 'LocalBusiness',
            '@id' => $this->id('#business'),
            'name' => (string) config('product.name'),
            'url' => Surface::Marketing->url(),
            'email' => $this->figures->contactEmail(),
            'parentOrganization' => ['@id' => $this->id('#organisation')],
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => (string) config('marketing.locality'),
                'addressRegion' => (string) config('marketing.region'),
                'addressCountry' => (string) config('marketing.country'),
            ],
            'areaServed' => ['@type' => 'Country', 'name' => (string) config('marketing.area_served')],
            'priceRange' => '££',
        ];
    }

    /** @return array<string, mixed> */
    private function service(string $title, string $description, string $url): array
    {
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

    private function id(string $fragment): string
    {
        return Surface::Marketing->url().'/'.$fragment;
    }
}
