<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionWrite
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $tenant = current_tenant();

        if ($tenant === null || $tenant->hasAdminWriteAccess()) {
            return $next($request);
        }

        if ($request->routeIs('billing.*', 'logout', 'impersonation.stop')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The diary is read-only until billing is up to date. Clients can still book online.',
            ], 403);
        }

        return back()->with('toast', 'The diary is read-only until billing is up to date. Clients can still book online.');
    }
}
