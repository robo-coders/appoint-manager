<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class MarketingController extends Controller
{
    public function home(): View
    {
        return view('marketing.home', $this->meta(
            'Stop losing money to no-shows',
            'Take a small deposit when someone books. Keep the diary full. Thirty days free, no card needed.',
        ));
    }

    public function pricing(): View
    {
        return view('marketing.pricing', $this->meta(
            'One plan. £39 a month.',
            'Or £390 a year. Thirty-day trial with no card. Cancel whenever you like.',
        ));
    }

    public function dogGrooming(): View
    {
        return view('marketing.dog-grooming', $this->meta(
            'Stop empty tables on a Saturday',
            'Dog grooming bookings with a deposit so a no-show does not wipe the morning.',
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
     * @return array{title: string, description: string, url: string}
     */
    private function meta(string $title, string $description): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'url' => url()->current(),
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
