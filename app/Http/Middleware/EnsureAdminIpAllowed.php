<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Restricts the admin surface to ADMIN_IP_ALLOWLIST when it is set.
 *
 * An empty allowlist means no restriction, which is right locally and wrong in
 * production — DEPLOY.md says so. A blocked request gets a 404 rather than a
 * 403: someone who is not allowed in should not learn that there is a door.
 */
class EnsureAdminIpAllowed
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = config('app.admin_ip_allowlist', []);

        if ($allowed === []) {
            return $next($request);
        }

        abort_unless(IpUtils::checkIp((string) $request->ip(), $allowed), 404);

        return $next($request);
    }
}
