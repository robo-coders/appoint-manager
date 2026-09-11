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
use App\Services\Billing\UnconfiguredBillingGateway;
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

            $configured = (bool) config('services.stripe.secret')
                && (bool) config('billing.monthly_price_id')
                && (bool) config('billing.billing_webhook_secret');

            if (! $configured) {
                if ($this->app->environment('local')) {
                    return new UnconfiguredBillingGateway;
                }

                throw new RuntimeException(
                    'STRIPE_SECRET is not set. Refusing to boot the billing gateway.'
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

    private function freezeClockForDeterministicRuns(): void
    {
        $frozen = env('FREEZE_NOW');

        if (! $frozen || $this->app->environment('production')) {
            return;
        }

        try {
            $at = CarbonImmutable::parse((string) $frozen);
        } catch (Throwable) {
            return;
        }

        CarbonImmutable::setTestNow($at);
        Carbon::setTestNow($at);
    }

    private function composeErrorPages(): void
    {
        View::composer(['errors.*', 'errors::*'], function (ViewContract $view): void {
            if (! preg_match('/(\\d{3})$/', $view->name(), $matches)) {
                return;
            }

            $view->with('page', ErrorPage::for(request(), (int) $matches[1]));
        });

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

        Blade::component('mail.layout', 'mail-layout');

        Auth::provider('eloquent-identity', fn ($app, array $config) => new IdentityUserProvider(
            $app['hash'],
            $config['model'],
        ));

        Gate::policy(User::class, StaffPolicy::class);
        Route::model('staff', User::class);
        Route::model('time_off', TimeOff::class);

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
            return [
                Limit::perMinute((int) config('booking_management.rate_limit_per_minute'))
                    ->by($request->ip().'|'.$request->route('token')),
                Limit::perMinute((int) config('booking_management.rate_limit_per_ip_per_minute'))
                    ->by($request->ip()),
            ];
        });

        RateLimiter::for('calendar-feed', function (Request $request) {
            return Limit::perMinute((int) config('calendar_sync.rate_limit_per_minute'))
                ->by($request->ip().'|'.$request->route('token'))
                ->response(fn (Request $request, array $headers) => response(
                    'Too many requests for this calendar feed. Try again shortly.',
                    429,
                    [...$headers, 'Content-Type' => 'text/plain; charset=utf-8'],
                ));
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(30)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(30)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(20)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('app', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        Vite::prefetch(concurrency: 3);
    }
}
