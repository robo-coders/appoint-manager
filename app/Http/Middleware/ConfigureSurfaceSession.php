<?php

namespace App\Http\Middleware;

use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Names and scopes the session cookie for the surface being served.
 *
 * This runs before StartSession, so by the time a session is opened the cookie
 * is already named per surface and pinned to the exact host. A cookie issued on
 * app.{domain} is not even named the same as one from admin.{domain}, and
 * neither is scoped to the parent domain, so neither can be presented to the
 * other.
 *
 * Never set a cookie on `.{domain}` (leading dot): that is precisely the
 * sharing this split exists to prevent.
 */
class ConfigureSurfaceSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $surface = Surface::fromHost($request->getHost());

        app()->instance(Surface::class, $surface);

        config([
            'session.cookie' => $surface->cookie(),
            // The exact host, never a parent domain.
            'session.domain' => Surface::routingBySubdomain() ? $request->getHost() : null,
            'session.same_site' => 'lax',
        ]);

        return $next($request);
    }
}
