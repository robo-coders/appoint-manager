<?php

namespace App\Providers;

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
use App\Support\TenantContext;
use RuntimeException;
use Stripe\StripeClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
                throw new RuntimeException(
                    'STRIPE_SECRET is not set. Refusing to boot: falling back to the fake gateway '
                    .'would accept forged webhook signatures and take no real payments.'
                );
            }

            if (! config('services.stripe.webhook_secret')) {
                throw new RuntimeException(
                    'STRIPE_WEBHOOK_SECRET is not set. Refusing to boot: webhook signatures could not be verified.'
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
    private function shouldUseFakeGateways(): bool
    {
        if ($this->app->environment('production')) {
            return false;
        }

        return $this->app->environment('testing') || (bool) config('services.stripe.fake');
    }

    public function boot(): void
    {
        Gate::policy(User::class, StaffPolicy::class);
        Route::model('staff', User::class);
        Route::model('time_off', TimeOff::class);

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
