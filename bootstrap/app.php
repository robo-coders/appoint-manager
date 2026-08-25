<?php

use App\Exceptions\SlotUnavailableException;
use App\Http\Middleware\ConfigureSurfaceSession;
use App\Http\Middleware\EnsureAdminIpAllowed;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Http\Middleware\EnsureSubscriptionWrite;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolvePublicTenant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // One app, four hostnames. See app/Support/SurfaceRoutes.php.
            App\Support\SurfaceRoutes::register();
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs before StartSession so the session cookie is named and scoped
        // per surface before a session is opened.
        $middleware->web(prepend: [
            ConfigureSurfaceSession::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);

        /*
         * One middleware group per surface. The per-surface rate limits live
         * here rather than on individual routes so a new route on a surface
         * inherits them without anyone remembering to add them.
         */
        $middleware->group('surface.marketing', []);
        $middleware->group('surface.app', ['throttle:app']);
        $middleware->group('surface.book', []);
        $middleware->group('surface.admin', ['admin-ip', 'throttle:admin']);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'onboarding' => EnsureOnboardingComplete::class,
            'public-tenant' => ResolvePublicTenant::class,
            'subscribed' => EnsureSubscriptionWrite::class,
            'super-admin' => EnsureSuperAdmin::class,
            'admin-ip' => EnsureAdminIpAllowed::class,
        ]);

        // Where an unauthenticated request is sent, per surface.
        $middleware->redirectGuestsTo(fn (Request $request) => App\Support\Surface::fromHost($request->getHost()) === App\Support\Surface::Admin
            ? App\Support\Surface::Admin->to('login')
            : App\Support\Surface::App->to('login'));

        // Where an already-authenticated request is sent away from a guest page.
        $middleware->redirectUsersTo(fn (Request $request) => App\Support\Surface::fromHost($request->getHost()) === App\Support\Surface::Admin
            ? App\Support\Surface::Admin->to()
            : home_route());

        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
            'stripe/billing/webhook',
            'twilio/status',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (SlotUnavailableException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return back()->withErrors(['starts_at' => $exception->getMessage()]);
        });

        $exceptions->render(function (\App\Exceptions\PaymentSetupFailedException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 503);
            }

            return back()->withErrors(['starts_at' => $exception->getMessage()]);
        });

        $exceptions->render(function (\App\Exceptions\OfferUnavailableException $exception, Request $request) {
            $status = str_contains($exception->getMessage(), 'expired') ? 410 : 409;

            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $status);
            }

            return response()->view('offer-taken', ['message' => $exception->getMessage()], $status);
        });
        $exceptions->reportable(function (\Throwable $e): void {
            if (function_exists('\\Sentry\\configureScope') && current_tenant_id()) {
                \Sentry\configureScope(function (\Sentry\State\Scope $scope): void {
                    $scope->setTag('tenant_id', (string) current_tenant_id());
                });
            }
        });
    })->create();