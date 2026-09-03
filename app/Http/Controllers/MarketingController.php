<?php

namespace App\Http\Controllers;

use App\Support\MarketingFigures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MarketingController extends Controller
{
    public function __construct(private MarketingFigures $figures) {}

    public function home(): View
    {
        return view('marketing.home', $this->meta(
            'The empty slot fills itself',
            'A cancellation goes straight to your waitlist by text, and the first person to reply '
                .'gets it. A deposit at booking means a no-show stops costing you. '
                .$this->figures->monthlyBare().' a month, '.$this->figures->trialDays()
                .' days free, no card.',
            'home',
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

    /**
     * The dog grooming trade page.
     *
     * `page` is `vertical`, not `dog-grooming`: the type scale it selects is the
     * trade-page scale, and the second trade page must land on the same one
     * rather than adding a third set of rules to the stylesheet.
     */
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

    /**
     * The contact form.
     *
     * **This does not email anybody yet, and the page it renders says so.** It
     * validates, throttles, drops anything that trips the honeypot, and writes
     * the enquiry to the log. Turning it into a real enquiry is replacing the
     * `Log::info` below with a Mailable to `config('billing.owner_alert_email')`;
     * everything around it — the validation, the limiter, the trap, the flash —
     * is already what it would need to be.
     *
     * A separate route from the page, outside the surface's public cache group,
     * because a form needs a session and a CSRF token and a shared cache must
     * never hand one visitor's token to another.
     */
    public function sendContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'business' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            // The honeypot. A person never sees the field, so a value in it is
            // a machine that filled every input on the page.
            'company_website' => ['prohibited'],
        ], [
            'company_website.prohibited' => 'That did not go through. Email us instead.',
            'message.min' => 'A line or two, so there is something to answer.',
        ]);

        /*
         * Five an hour from one address. The limiter is here rather than on the
         * route so the response is the form with a message on it rather than
         * Laravel's bare 429 page, which on a marketing site reads as a fault.
         */
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

    /**
     * The marketing host's 404. Rendered from `bootstrap/app.php`, not routed.
     *
     * @return array<string, mixed>
     */
    public static function notFoundData(): array
    {
        return [
            'title' => 'There is nothing at this address',
            'description' => 'That page has moved or the link was mistyped.',
            'url' => url()->current(),
            'figures' => app(MarketingFigures::class),
            'page' => 'not-found',
            'noindex' => true,
        ];
    }

    /**
     * The shell's variables, plus the figures every page is allowed to print.
     *
     * `figures` goes to every page rather than only the ones that use it today.
     * It is one object with no query behind it, the footer needs it for the
     * contact address, and the alternative is remembering to add it the first
     * time a legal page needs to name the price.
     *
     * @return array{title: string, description: string, url: string, figures: MarketingFigures, page: string}
     */
    private function meta(string $title, string $description, string $page): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'url' => url()->current(),
            'figures' => $this->figures,
            'page' => $page,
        ];
    }

    public function sitemap()
    {
        $urls = [
            route('marketing.home'),
            route('marketing.pricing'),
            route('marketing.how-it-works'),
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
