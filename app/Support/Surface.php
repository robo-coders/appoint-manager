<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Str;

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

    public function url(): string
    {
        return rtrim((string) config("app.surfaces.{$this->value}", config('app.url')), '/');
    }

    public function host(): ?string
    {
        if (! self::routingBySubdomain()) {
            return null;
        }

        return parse_url($this->url(), PHP_URL_HOST) ?: null;
    }

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

    public function to(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $prefix = $this->pathPrefix();

        if ($prefix !== '') {
            $path = $path === '' ? $prefix : "{$prefix}/{$path}";
        }

        return $path === '' ? $this->url() : $this->url().'/'.$path;
    }

    public function path(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $prefix = $this->pathPrefix();

        if ($prefix !== '') {
            $path = $path === '' ? $prefix : "{$prefix}/{$path}";
        }

        return $path === '' ? '/' : '/'.$path;
    }

    public function cookie(): string
    {
        $base = Str::slug((string) config('app.name', 'appoint manager'), '_');

        return match ($this) {
            self::Admin => "{$base}_admin_session",
            self::Book => "{$base}_book_session",
            default => "{$base}_app_session",
        };
    }

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
