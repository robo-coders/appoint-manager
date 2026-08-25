<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePublicTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('tenant_slug');

        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->whereNotNull('onboarding_completed_at')
            ->where('booking_page_live', true)
            ->first();

        if ($tenant === null) {
            abort(404);
        }

        $this->tenantContext->set($tenant);
        $request->attributes->set('public_tenant', $tenant);

        return $next($request);
    }
}
