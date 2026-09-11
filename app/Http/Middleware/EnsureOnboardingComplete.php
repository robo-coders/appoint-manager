<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    public function handle(Request $request, Closure $next): Response
    {
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
