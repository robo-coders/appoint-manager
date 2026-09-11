<?php

namespace App\Http\Middleware;

use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureSurfaceSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $surface = Surface::fromHost($request->getHost());

        app()->instance(Surface::class, $surface);

        config([
            'session.cookie' => $surface->cookie(),
            'session.domain' => Surface::routingBySubdomain() ? $request->getHost() : null,
            'session.same_site' => 'lax',
        ]);

        return $next($request);
    }
}
