<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * The four hostnames Appoint Manager serves.
 *
 * One app, one database, one deployment. The split exists so a session on one
 * surface cannot be presented to another, so super admin can be firewalled
 * separately, and so the booking page can be CDN-cached without the admin app
 * being cached with it.
 *
 * When subdomain routing is off every surface is served from APP_URL on the
 * path prefix it used before the split, so local development and CI need no DNS.
 */
enum Surface: string
{
    case Marketing = 'marketing';
    case App = 'app';
    case Book = 'book';
    case Admin = 'admin';

    public static function routingBySubdomain(): bool
    {
        return (bool) config('app.subdomain_routing');
    }

    /** The configured base URL for this surface. */
    public function url(): string
    {
        return rtrim((string) config("app.surfaces.{$this->value}", config('app.url')), '/');
    }

    /** The bare host, e.g. `app.appoint-manager.test`. Null when routing by path. */
    public function host(): ?string
    {
        if (! self::routingBySubdomain()) {
            return null;
        }

        return parse_url($this->url(), PHP_URL_HOST) ?: null;
    }

    /**
     * The path prefix this surface uses when everything shares one host.
     * Empty under subdomain routing, because the host is the prefix.
     */
    public function pathPrefix(): string
    {
        if (self::routingBySubdomain()) {
            return '';
        }

        return match ($this) {
            self::Book => 'book',
            self::Admin => 'admin',
            self::Marketing, self::App => '',
        };
    }

    /** Build an absolute URL on this surface. */
    public function to(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $prefix = $this->pathPrefix();

        if ($prefix !== '') {
            $path = $path === '' ? $prefix : "{$prefix}/{$path}";
        }

        return $path === '' ? $this->url() : $this->url().'/'.$path;
    }

    /**
     * The session cookie name. Distinct per surface so a cookie issued on one
     * host is not even *named* the same as another's.
     */
    public function cookie(): string
    {
        $base = Str::slug((string) config('app.name', 'appoint manager'), '_');

        return match ($this) {
            self::Admin => "{$base}_admin_session",
            self::Book => "{$base}_book_session",
            default => "{$base}_app_session",
        };
    }

    /** Resolve from a request host. Falls back to App when nothing matches. */
    public static function fromHost(?string $host): self
    {
        if ($host === null || ! self::routingBySubdomain()) {
            return self::App;
        }

        foreach (self::cases() as $surface) {
            if ($surface->host() !== null && strcasecmp($surface->host(), $host) === 0) {
                return $surface;
            }
        }

        return self::App;
    }

    /**
     * Which surface this request is actually on, in either routing mode.
     *
     * `fromHost` answers the question only under subdomain routing — with
     * routing by path it returns `App` for every host, because every surface
     * *is* the same host. That is correct for what it is used for (naming the
     * session cookie: one host, one cookie) and wrong for anything that has to
     * know which surface is being rendered, which locally and in CI is every
     * caller. `app.blade.php` asks this in order to set the console's density,
     * and asking `fromHost` would have made that dead code everywhere except
     * production.
     *
     * @param  string  $path  The request path, with or without a leading slash.
     */
    public static function current(?string $host, string $path): self
    {
        if (self::routingBySubdomain()) {
            return self::fromHost($host);
        }

        $path = trim($path, '/');

        foreach ([self::Admin, self::Book] as $surface) {
            $prefix = $surface->pathPrefix();

            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                return $surface;
            }
        }

        return self::App;
    }

    public static function bookUrlFor(Tenant $tenant): string
    {
        return self::Book->to($tenant->slug);
    }
}
