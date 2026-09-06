<?php

namespace App\Sandbox;

use App\BetaSandbox\BetaSandbox;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FlakyNetwork
{
    private static bool $registered = false;

    /** @var list<string> */
    private const ROUTES = [
        'bookings.store',
        'bookings.destroy',
        'bookings.approve',
        'bookings.decline',
    ];

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        app('router')->pushMiddlewareToGroup('web', self::class);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $user?->tenant_id ? Tenant::query()->find($user->tenant_id) : null;

        if (! BetaSandbox::enabled($tenant) || ! SandboxState::flaky($tenant)) {
            return $next($request);
        }

        $name = $request->route()?->getName();

        if (! is_string($name) || ! in_array($name, self::ROUTES, true)) {
            return $next($request);
        }

        usleep(random_int(200_000, 1_200_000));

        if (random_int(1, 100) > 28) {
            return $next($request);
        }

        $message = 'Sandbox: the network dropped. Nothing was saved.';

        if ($request->header('X-Inertia') || $request->expectsJson()) {
            return back()->withErrors(['starts_at' => $message]);
        }

        return back()->withErrors(['starts_at' => $message]);
    }
}
