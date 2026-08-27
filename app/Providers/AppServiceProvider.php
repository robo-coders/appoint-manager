<?php

namespace App\Providers;

use App\Auth\IdentityUserProvider;
use App\Exceptions\PaymentsNotConfiguredException;
use App\Models\TimeOff;
use App\Models\User;
use App\Policies\StaffPolicy;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\FakeBillingGateway;
use App\Services\Billing\StripeBillingGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\RecordingSmsGateway;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\TwilioSmsGateway;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\StripeConnectGateway;
use App\Services\Stripe\StripeGateway;
use App\Support\ErrorPage;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Stripe\StripeClient;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

        $this->app->singleton(StripeGateway::class, function () {
            if ($this->shouldUseFakeGateways()) {
                return new FakeStripeGateway;
            }

            if (! config('services.stripe.secret')) {
                throw PaymentsNotConfiguredException::missing(
                    'STRIPE_SECRET',
                    'Refusing to boot: falling back to the fake gateway would accept forged '
                    .'webhook signatures and take no real payments.'
                );
            }

            if (! config('services.stripe.webhook_secret')) {
                throw PaymentsNotConfiguredException::missing(
                    'STRIPE_WEBHOOK_SECRET',
                    'Refusing to boot: webhook signatures could not be verified.'
                );
            }

            return new StripeConnectGateway;
        });

        $this->app->singleton(BillingGateway::class, function () {
            if ($this->shouldUseFakeGateways()) {
                return new FakeBillingGateway;
            }

            if (! config('services.stripe.secret')) {
                throw new RuntimeException('STRIPE_SECRET is not set. Refusing to boot the billing gateway.');
            }

            if (! config('billing.monthly_price_id') || ! config('billing.billing_webhook_secret')) {
                throw new RuntimeException(
                    'Platform billing is not configured (STRIPE_PRICE_MONTHLY / STRIPE_BILLING_WEBHOOK_SECRET). '
                    .'Refusing to boot rather than silently faking subscriptions.'
                );
            }

            return new StripeBillingGateway(new StripeClient((string) config('services.stripe.secret')));
        });

        $this->app->singleton(SmsGateway::class, function () {
            if ($this->app->environment('testing')) {
                return new RecordingSmsGateway;
            }

            return match (config('services.sms.driver', 'log')) {
                'twilio' => new TwilioSmsGateway,
                default => new LogSmsGateway,
            };
        });
    }

    /**
     * The in-memory gateways are for tests and for a developer who has explicitly
     * asked for them. A missing secret is never a reason to use one.
     */
    /**
     * The fake gateways exist for the test suite and for nothing else.
     *
     * AUDIT C1. This used to answer true in any non-production environment with
     * `STRIPE_FAKE=true`, which meant a staging box — or a local box somebody
     * pointed a real Stripe webhook at — accepted the fake's literal
     * `t=1,v1=test` signature and would confirm any booking id an unauthenticated
     * request named. "Not production" is not a security boundary: `APP_ENV` is a
     * string in a file, and staging holds real data often enough.
     *
     * So: `testing` only. Every other environment resolves a real gateway or
     * throws at bind time, loudly, naming the variable that is missing — see the
     * bindings above. `STRIPE_FAKE` is gone; there is nothing left for it to
     * opt into.
     *
     * Local development therefore needs Stripe *test* keys in `.env`. That is a
     * smaller cost than it looks: they are free, they are already what staging
     * uses, and the alternative is a code path that is one `APP_ENV` typo away
     * from taking no money and confirming everything.
     */
    /**
     * Pin the server clock, when a run asks for one.
     *
     * The end-to-end screenshot baselines used to rot at midnight, and nothing
     * in the specs could stop it. `screens.spec.ts` freezes the *browser* clock
     * with `page.clock.setFixedTime`, but every value in those snapshots that
     * moves is computed in PHP: the dashboard's own date heading, the booking
     * page's "first available", and — worst — the demo seed itself, which walks
     * a window of days relative to `now()`, so which of them are Saturdays
     * shifts and `mt_rand(4, 6)` versus `mt_rand(2, 5)` consumes a different
     * amount of the seeded stream. Deterministic per day, different every day.
     *
     * So the day is an input now. `scripts/e2e-setup.sh` seeds with it set and
     * `playwright.config.ts` serves with it set, which makes the two agree — and
     * they have to agree, because a database seeded on one date and rendered on
     * another is the same bug with extra steps.
     *
     * Three guards, because a frozen clock in production would be a very quiet
     * catastrophe: production is refused outright, the value must parse, and
     * nothing happens at all unless `FREEZE_NOW` is explicitly set.
     */
    private function freezeClockForDeterministicRuns(): void
    {
        $frozen = env('FREEZE_NOW');

        if (! $frozen || $this->app->environment('production')) {
            return;
        }

        try {
            $at = CarbonImmutable::parse((string) $frozen);
        } catch (Throwable) {
            // A typo in an env var must not take the app down. It simply does
            // not freeze, and the snapshots fail loudly instead of silently.
            return;
        }

        CarbonImmutable::setTestNow($at);
        Carbon::setTestNow($at);
    }

    /**
     * The data every error view needs.
     *
     * Here, and not in `bootstrap/app.php`'s `withExceptions()` closure, where
     * the first version put it. That closure runs when the exception handler is
     * *resolved*, and `nunomaduro/collision` rebinds `ExceptionHandler` whenever
     * the app runs in the console — which is the whole Pest suite. So the
     * composer was never registered under test, `$page` was undefined, the view
     * threw, and the handler quietly fell back to Symfony's built-in page. Every
     * assertion failed against a page that was neither ours nor Laravel's.
     *
     * A view composer is not exception configuration. It belongs to the view
     * layer and is registered where the view layer is set up, which makes it
     * independent of which handler happens to be bound.
     *
     * It is also why the audience logic is not `@php(...)` at the top of each
     * template: Blade emits anything above `@extends` as output, which left an
     * unclosed buffer and marked every one of these tests "risky" in PHPUnit
     * while still passing them.
     */
    private function composeErrorPages(): void
    {
        /*
         * Both spellings, and that is not belt-and-braces.
         *
         * Rendered by hand the view is `errors.404`. Rendered by the framework
         * it is **`errors::404`** — `Handler::renderHttpException()` looks up
         * the namespaced view, and `registerErrorViewPaths()` points that
         * namespace at this same directory. `errors.*` does not match
         * `errors::404`, so a composer registered only for the first spelling
         * fires in a unit test and never in a browser: `$page` is undefined, the
         * view throws, and the handler falls back to the stock error page —
         * silently, because a failure inside the error handler has nowhere to be
         * reported to.
         */
        View::composer(['errors.*', 'errors::*'], function (ViewContract $view): void {
            if (! preg_match('/(\\d{3})$/', $view->name(), $matches)) {
                return;
            }

            $view->with('page', ErrorPage::for(request(), (int) $matches[1]));
        });

        /*
         * The reference on the 500 page.
         *
         * A salon owner cannot read a stack trace and should not be shown one.
         * The single useful thing they can do with a failure is quote an
         * identifier at us, and Sentry's event id is that identifier. Only
         * rendered when Sentry actually captured something: a reference that
         * does not resolve is worse than none, because support will search for
         * it and find nothing.
         */
        View::composer(['errors.500', 'errors::500'], function (ViewContract $view): void {
            $id = function_exists('\\Sentry\\lastEventId') ? \Sentry\lastEventId() : null;

            $view->with('reference', $id ? (string) $id : null);
        });
    }

    private function shouldUseFakeGateways(): bool
    {
        return $this->app->environment('testing');
    }

    public function boot(): void
    {
        $this->freezeClockForDeterministicRuns();
        $this->composeErrorPages();

        /*
         * `<x-mail-layout>` is `resources/views/mail/layout.blade.php`.
         *
         * Registered rather than moved into `views/components/`, because these
         * seven templates are a set and keeping the shell beside them is what
         * makes that visible. Anonymous, so it takes its data as attributes and
         * needs no class.
         */
        Blade::component('mail.layout', 'mail-layout');

        /*
         * The auth surface, and only the auth surface, may find a user without
         * a tenant context. `config/auth.php` points the `users` provider — the
         * `web` guard and the password broker both — at this driver.
         * See App\Auth\IdentityUserProvider.
         */
        Auth::provider('eloquent-identity', fn ($app, array $config) => new IdentityUserProvider(
            $app['hash'],
            $config['model'],
        ));

        Gate::policy(User::class, StaffPolicy::class);
        Route::model('staff', User::class);
        Route::model('time_off', TimeOff::class);

        /*
         * The impersonation handoff, and nothing else, binds a user across
         * tenants. A super admin has no tenant context — that is what makes
         * them one — so the target of `/impersonate/{user}` cannot be found
         * inside a tenant scope. Authority comes from the signature on the URL,
         * the single-use nonce, and the super-admin recheck in the controller,
         * all three of which run whether or not this binding finds a row.
         *
         * Declared here beside `Route::model('staff', …)` so both of the app's
         * `User` bindings are in one place and it is visible that exactly one
         * of them is the exception.
         */
        Route::bind('user', fn (string $value) => User::withoutGlobalScopes()
            ->whereKey($value)
            ->firstOrFail());

        RateLimiter::for('public-availability', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip().'|'.$request->route('tenant_slug'));
        });

        RateLimiter::for('public-booking', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip().'|'.$request->route('tenant_slug'));
        });

        RateLimiter::for('booking-manage', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip().'|'.$request->route('token'));
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        // Strictest of the three surfaces: this one is ours and has two users.
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(3)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Generous: she is operating this all day and should never meet a limit.
        RateLimiter::for('app', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        Vite::prefetch(concurrency: 3);
    }
}
