<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403, 'Tenant context is required.');
        }

        if ($user->tenant_id === null) {
            return $next($request);
        }

        $tenant = $user->tenant()->first();

        if ($tenant === null) {
            abort(403, 'Your account is not attached to a valid tenant.');
        }

        $this->tenantContext->set($tenant);

        if ($tenant->last_activity_at === null || $tenant->last_activity_at->lt(now()->subMinute())) {
            $tenant->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
