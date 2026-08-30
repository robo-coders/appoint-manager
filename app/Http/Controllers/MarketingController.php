<?php

namespace App\Http\Controllers;

use App\Support\MarketingFigures;
use Illuminate\View\View;

class MarketingController extends Controller
{
    public function __construct(private MarketingFigures $figures) {}

    public function home(): View
    {
        return view('marketing.home', $this->meta(
            'One refilled slot covers the month',
            'A client cancels, your waitlist gets a text, and the hour sells twice. '
                .$this->figures->monthlyBare().' a month, '.$this->figures->trialDays().' days free, no card.',
        ));
    }

    public function pricing(): View
    {
        return view('marketing.pricing', $this->meta(
            'Free is not free. It is billed to your clients.',
            'One price, '.$this->figures->monthlyBare().' a month, paid by you and not by the people who book with you. '
                .$this->figures->trialDays().'-day trial, no card.',
        ));
    }

    public function dogGrooming(): View
    {
        return view('marketing.dog-grooming', $this->meta(
            'Dog grooming: Saturday’s cancellation, sold twice',
            'Grooming software with a waitlist that refills a cancelled slot by text, deposits that '
                .'hold the hour, and a price list already set up for you.',
        ));
    }

    public function about(): View
    {
        return view('marketing.about', $this->meta(
            'Built for appointment businesses',
            'We run the diary, deposits, and reminders so you can run the salon.',
        ));
    }

    public function contact(): View
    {
        return view('marketing.contact', $this->meta(
            'Talk to us',
            'Questions about setup, deposits, or moving off paper. Email hello@'.parse_url((string) config('app.url'), PHP_URL_HOST),
        ));
    }

    public function privacy(): View
    {
        return view('marketing.privacy', $this->meta('Privacy', 'How we handle salon and client data in the UK and EU.'));
    }

    public function terms(): View
    {
        return view('marketing.terms', $this->meta('Terms', 'The agreement for using the product.'));
    }

    /**
     * The shell's variables, plus the figures every page is allowed to print.
     *
     * `figures` goes to all seven pages rather than only the three that use it
     * today. It is one object with no query behind it, and the alternative is
     * remembering to add it the first time a legal page needs to name the price.
     *
     * @return array{title: string, description: string, url: string, figures: MarketingFigures}
     */
    private function meta(string $title, string $description): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'url' => url()->current(),
            'figures' => $this->figures,
        ];
    }

    public function sitemap()
    {
        $urls = [
            route('marketing.home'),
            route('marketing.pricing'),
            route('marketing.dog-grooming'),
            route('marketing.about'),
            route('marketing.contact'),
            route('marketing.privacy'),
            route('marketing.terms'),
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $url) {
            $xml .= '<url><loc>'.e($url).'</loc></url>';
        }

        $xml .= '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots()
    {
        $body = "User-agent: *\nAllow: /\nSitemap: ".url('/sitemap.xml')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }
}
