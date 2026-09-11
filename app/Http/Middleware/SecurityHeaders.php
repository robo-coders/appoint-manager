<?php

namespace App\Http\Middleware;

use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
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

    private function preventBackButtonPainting(Request $request, Response $response): void
    {
        if (! $request->hasSession() || ! $request->user()) {
            return;
        }

        $type = (string) $response->headers->get('Content-Type', '');

        if (! str_contains($type, 'text/html')) {
            return;
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
    }

    private function policyFor(Surface $surface): string
    {
        $self = Surface::routingBySubdomain() ? $surface->url() : "'self'";

        $vite = $this->viteDevOrigin();
        $dev = $vite === null ? '' : ' '.$vite;
        $devSocket = $vite === null ? '' : ' '.$vite.' '.preg_replace('/^http/', 'ws', $vite);

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self' {$self}",
            "img-src 'self' data: https:{$dev}",
            "style-src 'self' 'unsafe-inline'{$dev}",
            "font-src 'self'{$dev}",
        ];

        if ($surface === Surface::Book || $surface === Surface::App) {
            $directives[] = "script-src 'self' 'unsafe-inline' https://js.stripe.com{$dev}";
            $directives[] = "connect-src 'self' https://api.stripe.com{$devSocket}";
            $directives[] = 'frame-src https://js.stripe.com https://hooks.stripe.com';
        } elseif ($surface === Surface::Marketing) {
            $directives[] = "script-src 'self' 'unsafe-inline' https://plausible.io{$dev}";
            $directives[] = "connect-src 'self' https://plausible.io{$devSocket}";
            $directives[] = "frame-src 'none'";
        } else {
            $directives[] = "script-src 'self' 'unsafe-inline'{$dev}";
            $directives[] = "connect-src 'self'{$devSocket}";
            $directives[] = "frame-src 'none'";
        }

        return implode('; ', $directives);
    }

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
