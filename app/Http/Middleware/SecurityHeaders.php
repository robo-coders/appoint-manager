<?php

namespace App\Http\Middleware;

use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
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

        // Empty in every environment but local-with-`npm run dev`. See viteDevOrigin().
        $vite = $this->viteDevOrigin();
        $dev = $vite === null ? '' : ' '.$vite;
        $devSocket = $vite === null ? '' : ' '.$vite.' '.preg_replace('/^http/', 'ws', $vite);

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self' {$self}",
            "img-src 'self' data: https:",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net{$dev}",
            "font-src 'self' https://fonts.bunny.net",
        ];

        if ($surface === Surface::Book) {
            $directives[] = "script-src 'self' 'unsafe-inline' https://js.stripe.com{$dev}";
            $directives[] = "connect-src 'self' https://api.stripe.com{$devSocket}";
            $directives[] = 'frame-src https://js.stripe.com https://hooks.stripe.com';
        } elseif ($surface === Surface::Marketing) {
            $directives[] = "script-src 'self' 'unsafe-inline' https://plausible.io{$dev}";
            $directives[] = "connect-src 'self' https://plausible.io{$devSocket}";
            $directives[] = "frame-src 'none'";
        } else {
            // The operator app and the console never frame anything and never
            // load a third-party script.
            $directives[] = "script-src 'self' 'unsafe-inline'{$dev}";
            $directives[] = "connect-src 'self'{$devSocket}";
            $directives[] = "frame-src 'none'";
        }

        return implode('; ', $directives);
    }

    /**
     * The Vite dev server's origin, or null when it is not serving.
     *
     * `npm run dev` serves assets from its own origin, so `'self'` excludes
     * every one of them and the page loads nothing. The origin is read from
     * the hot file Vite writes on boot and deletes on exit, so there is no
     * port duplicated here to drift, and the carve-out is gone the moment the
     * dev server is. It is additionally gated on the local environment: a
     * stale hot file deployed anywhere else must never widen the policy.
     */
    private function viteDevOrigin(): ?string
    {
        if (! app()->environment('local') || ! Vite::isRunningHot()) {
            return null;
        }

        $contents = @file_get_contents(Vite::hotFile());

        if ($contents === false) {
            return null;
        }

        $parts = parse_url(rtrim($contents));

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        if (($parts['host'] ?? '') === '') {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
