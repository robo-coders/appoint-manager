<?php

use App\Models\Tenant;
use App\Support\Surface;
use App\Support\TenantContext;

if (! function_exists('current_tenant')) {
    function current_tenant(): ?Tenant
    {
        return app(TenantContext::class)->tenant();
    }
}

if (! function_exists('current_tenant_id')) {
    function current_tenant_id(): ?int
    {
        return app(TenantContext::class)->id();
    }
}

if (! function_exists('home_route')) {
    /**
     * Where a user lands after logging in, verifying an email or confirming a
     * password.
     *
     * A super admin belongs in the console, on its own hostname — not in a
     * tenant's diary, where the onboarding gate would bounce them to
     * /onboarding. While impersonating, the authenticated user is the salon
     * owner rather than the super admin, so this correctly returns the diary.
     */
    function home_route(): string
    {
        if (auth()->user()?->is_super_admin && ! session()->has('impersonator_id')) {
            return admin_url();
        }

        return app_url('diary');
    }
}

if (! function_exists('safe_json')) {
    /**
     * JSON for embedding inside a <script> block.
     *
     * Every flag here matters. Without JSON_HEX_TAG a value containing "</script>"
     * closes the block and everything after it is parsed as HTML, which is a stored
     * XSS whenever the value is tenant- or user-controlled. Use this everywhere
     * rather than hand-rolling the flags per template.
     *
     * @param  mixed  $value
     */
    function safe_json($value): string
    {
        return (string) json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
    }
}

/*
|--------------------------------------------------------------------------
| Cross-surface URLs
|--------------------------------------------------------------------------
|
| Appoint Manager is served from four hostnames. Any link that crosses a
| surface boundary must be absolute and must name the right host, so these
| helpers exist rather than hand-written paths. `route()` already does the
| right thing for a named route bound to a domain; use these when building a
| URL by hand.
|
*/

if (! function_exists('marketing_url')) {
    function marketing_url(string $path = ''): string
    {
        return Surface::Marketing->to($path);
    }
}

if (! function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        return Surface::App->to($path);
    }
}

if (! function_exists('book_url')) {
    /** The public booking page for a tenant, or a path on the booking host. */
    function book_url(Tenant|string|null $tenant = null, string $path = ''): string
    {
        if ($tenant instanceof Tenant) {
            $base = $tenant->slug;

            return Surface::Book->to($path === '' ? $base : $base.'/'.ltrim($path, '/'));
        }

        return Surface::Book->to($tenant ?? $path);
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return Surface::Admin->to($path);
    }
}

if (! function_exists('current_surface')) {
    function current_surface(): Surface
    {
        return app()->bound(Surface::class) ? app(Surface::class) : Surface::App;
    }
}
