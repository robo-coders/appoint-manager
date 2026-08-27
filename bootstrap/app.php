<?php

use App\Exceptions\OfferUnavailableException;
use App\Exceptions\PaymentSetupFailedException;
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
use App\Support\Surface;
use App\Support\SurfaceRoutes;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Sentry\State\Scope;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // One app, four hostnames. See app/Support/SurfaceRoutes.php.
            SurfaceRoutes::register();
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

        /*
         * `tenant` has to run before route model binding, and by default it does
         * not.
         *
         * `SubstituteBindings` is in Laravel's own middleware priority list;
         * `ResolveTenant` — being a route-level alias — is not, so the sort put
         * bindings first. Every tenant-scoped model is behind `TenantScope`,
         * which fails **closed** when there is no tenant context: it appends
         * `0 = 1` rather than reading across tenants. So the binding for
         * `/customers/{customer}` was resolved with no context, matched nothing,
         * and Laravel turned that into a 404.
         *
         * The effect was that every operator screen reached by a model-bound
         * route — a customer, a booking, a service, updating a member of staff,
         * deleting a block of time off — returned "not found" for a row that was
         * right there in the list you clicked it from. It reads as missing data
         * rather than as a middleware ordering bug, which is why it survived: the
         * index pages all query inside the controller, where the context exists,
         * so every list worked and everything you could click on a list did not.
         *
         * Placed immediately before `SubstituteBindings`, which is after
         * `Authenticate` — `ResolveTenant` needs `$request->user()` and aborts
         * 403 without one. This only reorders routes that already carry the
         * middleware; nothing else gains it.
         *
         * The public booking surface needs no equivalent: `ResolvePublicTenant`
         * guards routes that take `{token}` and `{tenant_slug}` as strings and
         * load their own models, so there is no binding there to be ordered
         * against.
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'onboarding' => EnsureOnboardingComplete::class,
            'public-tenant' => ResolvePublicTenant::class,
            'subscribed' => EnsureSubscriptionWrite::class,
            'super-admin' => EnsureSuperAdmin::class,
            'admin-ip' => EnsureAdminIpAllowed::class,
        ]);

        /*
         * Where an unauthenticated request is sent, per surface.
         *
         * `Surface::current`, not `Surface::fromHost`. `fromHost` answers by
         * host and returns `App` unconditionally when subdomain routing is off,
         * so locally and in CI a guest hitting `/admin` was sent to `/login` —
         * the salon owners' door — rather than to the console's. It failed
         * closed either way, because the console needs a super admin whichever
         * form you sign in on, and it was correct in production where subdomain
         * routing is on. It was still the wrong door, and it was recorded in
         * DECISIONS.md as such.
         */
        $middleware->redirectGuestsTo(fn (Request $request) => Surface::current($request->getHost(), $request->path()) === Surface::Admin
            ? Surface::Admin->to('login')
            : Surface::App->to('login'));

        // Where an already-authenticated request is sent away from a guest page.
        $middleware->redirectUsersTo(fn (Request $request) => Surface::current($request->getHost(), $request->path()) === Surface::Admin
            ? Surface::Admin->to()
            : home_route());

        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
            'stripe/billing/webhook',
            'twilio/status',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * A stale session, and a way back to what you were doing.
         *
         * `TokenMismatchException` had no render hook at all, so an operator
         * whose session went stale mid-shift dropped out of the product's
         * visual language entirely and into Laravel's stock "419 | Page
         * Expired" — which is grey, framework-branded, and a genuine dead end.
         * DECISIONS.md recorded it as an error-states item; this clears it.
         *
         * The recovery is the point. The page they were *on* is the referrer,
         * not the URL that failed — a 419 is almost always a POST, and sending
         * somebody back to `POST /bookings` helps nobody. Storing it as the
         * intended URL means `redirect()->intended()` after signing in returns
         * them there.
         *
         * Only same-origin referrers are kept. `url.intended` survives the
         * login redirect and is followed without further checks, so an
         * attacker-controlled `Referer` would be an open redirect handed to us
         * for free.
         *
         * Registered against `HttpException` with a status check rather than
         * against `TokenMismatchException`, and that is not a preference:
         * `Handler::render()` calls `prepareException()` — which converts a
         * `TokenMismatchException` into `HttpException(419)` — **before**
         * `renderViaCallbacks()`. A callback type-hinted on the original class
         * is registered and never fires, which is a silent no-op rather than an
         * error, and the stock page keeps rendering.
         */
        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() !== 419) {
                return null;
            }

            $previous = $request->headers->get('referer');
            $intended = null;

            if (is_string($previous) && str_starts_with($previous, $request->getSchemeAndHttpHost().'/')) {
                $intended = $previous;
                $request->session()?->put('url.intended', $previous);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Sign in again and this will work.',
                ], 419);
            }

            return response()->view('errors.419', [
                // The path only. A full URL with a query string is a line of
                // noise on a page whose job is to be read quickly.
                'intended' => $intended === null ? null : (parse_url($intended, PHP_URL_PATH) ?: '/'),
            ], 419);
        });

        $exceptions->render(function (SlotUnavailableException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return back()->withErrors(['starts_at' => $exception->getMessage()]);
        });

        $exceptions->render(function (PaymentSetupFailedException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 503);
            }

            return back()->withErrors(['starts_at' => $exception->getMessage()]);
        });

        $exceptions->render(function (OfferUnavailableException $exception, Request $request) {
            $status = str_contains($exception->getMessage(), 'expired') ? 410 : 409;

            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $status);
            }

            return response()->view('offer-taken', ['message' => $exception->getMessage()], $status);
        });
        $exceptions->reportable(function (Throwable $e): void {
            if (function_exists('\\Sentry\\configureScope') && current_tenant_id()) {
                \Sentry\configureScope(function (Scope $scope): void {
                    $scope->setTag('tenant_id', (string) current_tenant_id());
                });
            }
        });

        /*
         * The reference on the 500 page.
         *
         * A salon owner cannot read a stack trace and should not be shown one.
         * The single useful thing they can do with a failure is quote an
         * identifier at us, and Sentry's event id is that identifier.
         *
         * Only rendered when Sentry actually captured something — a reference
         * that does not resolve is worse than no reference, because support
         * will search for it and find nothing. Shared with the view here rather
         * than looked up inside it, because the view runs on 503 too and must
         * not reach for an SDK that may not be installed.
         */
    })->create();
