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
    function home_route(): string
    {
        if (auth()->user()?->is_super_admin && ! session()->has('impersonator_id')) {
            return Surface::Admin->path();
        }

        return Surface::App->path('diary');
    }
}

if (! function_exists('safe_json')) {
    function safe_json($value): string
    {
        return (string) json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
    }
}

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
    function book_url(Tenant|string|null $tenant = null, string $path = ''): string
    {
        if ($tenant instanceof Tenant) {
            $base = $tenant->slug;

            return Surface::Book->to($path === '' ? $base : $base.'/'.ltrim($path, '/'));
        }

        return Surface::Book->to($tenant ?? $path);
    }
}

if (! function_exists('booking_url_is_loopback')) {
    function booking_url_is_loopback(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = trim($host, '[]');

        return in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true);
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
