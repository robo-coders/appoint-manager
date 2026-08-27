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

        $this->preventBackButtonPainting($request, $response);

        return $response;
    }

    /**
     * Stop the back button repainting a signed-out screen.
     *
     * Laravel's default on an authenticated response is `no-cache, private`.
     * The server side of that is correct — a real back-navigation revalidates
     * and lands on the login — but `no-cache` does not disable the browser's
     * **back/forward cache**, which restores a page from memory without asking
     * anybody. So after logging out, the back button could still *paint* the
     * last screen of somebody's diary, with their clients' names on it, until
     * something on the page happened to make a request.
     *
     * `no-store` is what disqualifies a response from bfcache. It is set only
     * where it earns its cost — a response carrying a session, i.e. a signed-in
     * page — because it also forbids ordinary caching, and the booking page and
     * the marketing site are the two things here that most want to be cached.
     *
     * Recorded in DECISIONS.md as an error-states item, on the grounds that it
     * is a behaviour change on every response. It is not: it is a behaviour
     * change on authenticated HTML, which is the only place the bug existed.
     */
    private function preventBackButtonPainting(Request $request, Response $response): void
    {
        if (! $request->hasSession() || ! $request->user()) {
            return;
        }

        // Only documents. A `no-store` on a hashed asset would defeat the
        // fingerprinting that makes it cacheable forever in the first place.
        $type = (string) $response->headers->get('Content-Type', '');

        if (! str_contains($type, 'text/html')) {
            return;
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
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
            "style-src 'self' 'unsafe-inline'{$dev}",
            /*
             * No font host. Geist and Geist Mono are self-hosted woff2 —
             * resources/fonts/, declared in resources/css/base.css — so the
             * fonts.bunny.net carve-out that used to sit in both of these
             * directives is gone from both.
             *
             * `{$dev}` is here and not only on style-src: while `npm run dev` is
             * running, the stylesheet is served from the Vite origin and so are
             * the `url()` targets inside it, which makes every font file
             * cross-origin. Without this the type silently falls back to the
             * system face in development only — the exact failure the
             * self-hosting was meant to end.
             */
            "font-src 'self'{$dev}",
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
