<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminIpAllowed
{
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
