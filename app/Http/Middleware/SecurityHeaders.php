<?php

namespace App\Http\Middleware;

use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $surface = Surface::fromHost($request->getHost());

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', $this->policyFor($surface));

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Only the booking surface may frame or talk to Stripe: it is the only one
     * that takes a card. `form-action` is pinned to each surface's own origin
     * so a form on one can never post to another.
     */
    private function policyFor(Surface $surface): string
    {
        $self = Surface::routingBySubdomain() ? $surface->url() : "'self'";

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self' {$self}",
            "img-src 'self' data: https:",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net",
        ];

        if ($surface === Surface::Book) {
            $directives[] = "script-src 'self' 'unsafe-inline' https://js.stripe.com";
            $directives[] = "connect-src 'self' https://api.stripe.com";
            $directives[] = 'frame-src https://js.stripe.com https://hooks.stripe.com';
        } elseif ($surface === Surface::Marketing) {
            $directives[] = "script-src 'self' 'unsafe-inline' https://plausible.io";
            $directives[] = "connect-src 'self' https://plausible.io";
            $directives[] = "frame-src 'none'";
        } else {
            // The operator app and the console never frame anything and never
            // load a third-party script.
            $directives[] = "script-src 'self' 'unsafe-inline'";
            $directives[] = "connect-src 'self'";
            $directives[] = "frame-src 'none'";
        }

        return implode('; ', $directives);
    }
}
