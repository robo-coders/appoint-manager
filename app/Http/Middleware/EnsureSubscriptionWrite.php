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

        /*
         * A super admin is not subject to a tenant's billing lock — unless they
         * are impersonating, in which case they are.
         *
         * The stated reason for the second half is "I want to see exactly what
         * she sees": a lock that a super admin can walk through while wearing an
         * owner's session is a lock nobody can reproduce a support ticket
         * against. Outside impersonation the lock is meaningless in the other
         * direction — it exists to stop an unpaid salon writing to its own
         * diary, and we are not that salon.
         *
         * `impersonator_id` is the session key `ImpersonationController::start`
         * sets, and it is the only thing that distinguishes the two cases: the
         * authenticated user while impersonating *is* the owner, so
         * `is_super_admin` is false either way.
         */
        if ($request->user()?->is_super_admin && ! $request->session()->has('impersonator_id')) {
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
