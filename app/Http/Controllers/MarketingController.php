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
     * The contact form. **It emails a person now.**
     *
     * It used to validate, throttle, honeypot and then `Log::info` the enquiry,
     * and the page it rendered had to say so — an enquiry reached a log file and
     * nobody read it. The log line stays: it is the record that a submission
     * happened, which matters precisely when the mail is the thing that failed.
     * `MarketingEnquiryMail` is queued to `config('billing.owner_alert_email')`
     * with the enquirer on `replyTo`.
     *
     * Queued, not sent inline. A slow or unreachable mail host must not turn a
     * marketing form into a timeout — the submission is already durable in the
     * log by the time the job is dispatched, and the visitor is told it has come
     * through because it has.
     *
     * `owner_alert_email` falls back to `MAIL_FROM_ADDRESS`, and on an
     * environment where neither is set it is null — so the send is skipped
     * rather than throwing on a null recipient, and the log line is again the
     * whole record. `LAUNCH.md` is where that is a deployment step.
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
            'faq' => [],
            /*
             * The organisation node, and nothing else. A 404 is still a page on
             * a real site and saying whose site it is costs nothing; claiming it
             * is a `LocalBusiness` page, or attaching an FAQ to it, would be
             * describing a page that does not exist.
             */
            'schema' => app(MarketingSchema::class)->graph('not-found', url()->current(), '', ''),
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
     * `faq` and `schema` travel with them. The FAQ is a list this page renders
     * as headings and paragraphs; the schema is the same list serialised as
     * `FAQPage` JSON-LD alongside the organisation and, where the page has one,
     * its `LocalBusiness` or `Service` node. Both come out of one array so the
     * markup cannot say something the page does not — see `MarketingFaq`.
     *
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

    /**
     * The sitemap, read off the router.
     *
     * It was eight `route()` calls typed into an array here, which is the route
     * file's list copied — so a ninth page was a page no crawler heard about,
     * and nothing failed, because a short sitemap is still valid XML. See
     * `MarketingSitemap` for the rule that replaced it.
     *
     * No `<lastmod>`, no `<changefreq>`, no `<priority>`. A `lastmod` that is
     * `now()` on every request tells a crawler the whole site changed every time
     * it looked, which is worse than saying nothing; the other two are ignored
     * by every major crawler and were never emitted.
     */
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

    /**
     * `robots.txt` for the marketing host.
     *
     * **There used to be a `public/robots.txt` as well, and it won.** A file in
     * the document root is served by nginx before the request reaches PHP, so
     * this route was dead in production — and because all four hostnames share
     * one document root, that one static file was also the answer for
     * `app.`, `book.` and `admin.`. It said `User-agent: * / Disallow:` with no
     * sitemap: crawlable operator app, crawlable console, and no sitemap
     * anywhere. It is deleted, and each surface answers for itself; see
     * `SurfaceRoutes::robots()` for the three that say no.
     */
    public function robots(): Response
    {
        $body = "User-agent: *\nAllow: /\n\nSitemap: ".route('marketing.sitemap')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * `llms.txt` — what this product is, in plain text, for an answer engine.
     *
     * The convention is a Markdown-flavoured plain-text file at the root: an
     * `H1` with the product name, a blockquote summarising it, and linked
     * sections. It is not a robots file and grants nothing; it is the summary we
     * would rather a model quote than one it assembled out of the nav.
     *
     * Every figure in it is read from config through `MarketingFigures`, and the
     * page list comes off the router through `MarketingSitemap`, for the same
     * reason the sitemap does: a file that has to be edited by hand when a page
     * is added is a file that goes stale silently.
     */
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

    /**
     * One line describing a page, for `llms.txt`.
     *
     * Keyed by route name so a renamed page is a missing key rather than a
     * silently wrong description, and a page with no entry falls back to its
     * route's last segment — which is a poor label but never a false one.
     */
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
