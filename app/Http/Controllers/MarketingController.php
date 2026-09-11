<?php

namespace App\Http\Controllers;

use App\Mail\MarketingEnquiryMail;
use App\Support\MarketingFaq;
use App\Support\MarketingFigures;
use App\Support\MarketingSchema;
use App\Support\MarketingSitemap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MarketingController extends Controller
{
    public function __construct(
        private MarketingFigures $figures,
        private MarketingFaq $faq,
        private MarketingSchema $schema,
    ) {}

    public function home(): View
    {
        return view('marketing.home', $this->meta(
            'The empty slot fills itself',
            'A cancellation goes straight to your waitlist by text, and the first person to reply '
                .'gets it. A deposit at booking means a no-show stops costing you. '
                .$this->figures->monthlyBare().' a month, '.$this->figures->trialDays()
                .' days free, no card.',
            'home',
            $this->faq->home(),
        ));
    }

    public function pricing(): View
    {
        return view('marketing.pricing', $this->meta(
            'One price. Everything included.',
            'No tiers to grow into, and no fee added to your customer\'s booking. '
                .$this->figures->monthlyBare().' a month or '.$this->figures->yearlyBare().' a year. '
                .$this->figures->trialDays().'-day trial, no card.',
            'pricing',
            $this->faq->pricing(),
        ));
    }

    public function howItWorks(): View
    {
        return view('marketing.how-it-works', $this->meta(
            'Three steps. No manual work.',
            'From the booking to the refill, '.config('product.name').' does the part that used '
                .'to cost you money.',
            'how-it-works',
        ));
    }

    public function dogGrooming(): View
    {
        $vertical = $this->figures->vertical('groomer');

        return view('marketing.dog-grooming', $this->meta(
            $vertical->label().': Saturday\'s cancellation, sold twice',
            'Grooming software with a waitlist that refills a cancelled slot by text, deposits '
                .'that hold the hour, and a price list already set up for you.',
            'vertical',
        ));
    }

    public function about(): View
    {
        return view('marketing.about', $this->meta(
            'A new product, built in the open',
            'One person, in Scotland, building booking software for businesses that lose money '
                .'when somebody does not turn up.',
            'doc',
        ));
    }

    public function contact(): View
    {
        return view('marketing.contact', $this->meta(
            'Ask me anything about it',
            'Questions about setup, deposits, or moving off paper. Businesses in and around East '
                .'Kilbride can have it set up in person.',
            'contact',
        ));
    }

    public function sendContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'business' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            'company_website' => ['prohibited'],
        ], [
            'company_website.prohibited' => 'That did not go through. Email us instead.',
            'message.min' => 'A line or two, so there is something to answer.',
        ]);

        $key = 'marketing-contact:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'message' => 'That is a few messages in a short time. Try again in an hour, or '
                    .'email '.$this->figures->contactEmail().'.',
            ]);
        }

        RateLimiter::hit($key, 3600);

        Log::info('Marketing enquiry', [
            'name' => $data['name'],
            'business' => $data['business'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'message' => $data['message'],
        ]);

        $to = config('billing.owner_alert_email');

        if (filled($to)) {
            Mail::to($to)->queue(new MarketingEnquiryMail(
                $data['name'],
                $data['business'],
                $data['email'],
                $data['phone'] ?? null,
                $data['message'],
            ));
        }

        return redirect()
            ->route('marketing.contact')
            ->with('contact.sent', 'Thanks. That has come through and you will get an answer from '
                .'a person, usually the same day.');
    }

    public function privacy(): View
    {
        return view('marketing.privacy', $this->meta(
            'What we hold, and why',
            'How '.config('product.name').' handles salon and customer data under UK GDPR: what '
                .'is collected, why, how long it is kept and who else touches it.',
            'doc',
        ));
    }

    public function terms(): View
    {
        return view('marketing.terms', $this->meta(
            'The agreement, in plain words',
            'The subscription, the free trial, who is responsible for a deposit, how text message '
                .'allowances work, and what happens to your data if you leave.',
            'doc',
        ));
    }

    /** @return array<string, mixed> */
    public static function notFoundData(): array
    {
        return [
            'title' => 'There is nothing at this address',
            'description' => 'That page has moved or the link was mistyped.',
            'url' => url()->current(),
            'figures' => app(MarketingFigures::class),
            'page' => 'not-found',
            'noindex' => true,
            'faq' => [],
            'schema' => app(MarketingSchema::class)->graph('not-found', url()->current(), '', ''),
        ];
    }

    /**
     * @param  list<array{question: string, answer: string}>  $faq
     * @return array{title: string, description: string, url: string, figures: MarketingFigures, page: string, faq: list<array{question: string, answer: string}>, schema: array<string, mixed>}
     */
    private function meta(string $title, string $description, string $page, array $faq = []): array
    {
        $url = url()->current();

        return [
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'figures' => $this->figures,
            'page' => $page,
            'faq' => $faq,
            'schema' => $this->schema->graph($page, $url, $title, $description, $faq),
        ];
    }

    public function sitemap(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach (MarketingSitemap::pages() as $page) {
            $xml .= '<url><loc>'.e($page['url']).'</loc></url>';
        }

        $xml .= '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $body = "User-agent: *\nAllow: /\n\nSitemap: ".route('marketing.sitemap')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function llms(): Response
    {
        $name = (string) config('product.name');
        $lines = [
            '# '.$name,
            '',
            '> '.$name.' is appointment booking software for small service businesses — dog '
                .'groomers, barbers, beauty and nail salons — that lose money when somebody does not '
                .'turn up. Customers book on the business\'s own link, a deposit is held on the card '
                .'at booking, and a cancelled appointment is texted to the waitlist automatically so '
                .'the first person to reply takes the slot.',
            '',
            'One price, everything included: '.$this->figures->monthlyBare().' a month or '
                .$this->figures->yearlyBare().' a year, with a '.$this->figures->trialDays()
                .'-day free trial that takes no card. Customers are never charged a booking fee, and '
                .'deposits go to the business\'s own Stripe account rather than through '.$name.'.',
            '',
            'Built and run by one person in '.config('marketing.locality').', '
                .config('marketing.region').'. Not affiliated with any other booking product.',
            '',
            '## What it does',
            '',
            '- Online booking page on the business\'s own link, showing only genuinely free times',
            '- Deposits taken at booking, into the business\'s own Stripe account',
            '- Waitlist that texts '.$this->figures->offerBatch().' matching customers when a slot '
                .'opens; first to claim within '.$this->figures->offerMinutes().' minutes gets it',
            '- Reminders, rebooking prompts and a daily agenda',
            '- Request mode, where a booking is a request the owner confirms',
            '- CSV import of existing customers and past appointments, with a dry run first',
            '- '.$this->figures->smsIncluded().' texts a month included, then '
                .$this->figures->smsTopupBare().' per '.$this->figures->smsTopupSize(),
            '',
            '## Pages',
            '',
        ];

        foreach (MarketingSitemap::pages() as $page) {
            $lines[] = '- ['.$this->describe($page['name']).']('.$page['url'].')';
        }

        $lines[] = '';
        $lines[] = '## Contact';
        $lines[] = '';
        $lines[] = '- '.$this->figures->contactEmail();
        $lines[] = '';

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function describe(string $route): string
    {
        return match ($route) {
            'marketing.home' => 'Home — what it does and what changes on day one',
            'marketing.how-it-works' => 'How it works — booking, deposit, waitlist refill',
            'marketing.pricing' => 'Pricing — one price, what is included, and questions about it',
            'marketing.dog-grooming' => 'Dog grooming — the trade page, with the price list a groomer starts from',
            'marketing.about' => 'About — who builds it',
            'marketing.contact' => 'Contact — questions, and the in-person setup offer',
            'marketing.privacy' => 'Privacy — what data is held, why, and for how long',
            'marketing.terms' => 'Terms — the subscription, the trial, deposits and leaving',
            default => ucfirst(str_replace('-', ' ', (string) last(explode('.', $route)))),
        };
    }
}
