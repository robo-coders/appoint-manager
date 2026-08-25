<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // A super admin is never setting up a salon of their own, so a tenant's
        // unfinished onboarding must not stand between them and the product.
        // While impersonating, the authenticated user is the owner, so the gate
        // still applies exactly as it does for that owner.
        if ($request->user()?->is_super_admin) {
            return $next($request);
        }

        $tenant = current_tenant();

        if ($tenant !== null && ! $tenant->hasCompletedOnboarding()) {
            return redirect()->route('onboarding.show');
        }

        return $next($request);
    }
}
