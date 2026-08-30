<?php

namespace App\Support;

use App\Http\Controllers\Dev\ComponentGalleryController;
use Illuminate\Support\Facades\Route;

/**
 * Registers each surface's routes against its own hostname.
 *
 * Under subdomain routing every file is bound to one host and carries no path
 * prefix. Without it, all four are served from APP_URL on the prefixes they
 * used before the split, so nothing has to be added to /etc/hosts to run the
 * app or the test suite.
 */
final class SurfaceRoutes
{
    public static function register(): void
    {
        self::surface(Surface::Marketing, __DIR__.'/../../routes/marketing.php');
        self::surface(Surface::App, __DIR__.'/../../routes/app.php');
        self::surface(Surface::Book, __DIR__.'/../../routes/book.php');
        self::surface(Surface::Admin, __DIR__.'/../../routes/admin.php');

        // Not a surface: no session, no browsing, signature-authenticated.
        // Registered without a host constraint so a provider's configured URL
        // keeps working whichever hostname it points at.
        Route::middleware('web')->group(__DIR__.'/../../routes/machine.php');

        self::manifest();
        self::gallery();
        self::errorPreviews();
    }

    /**
     * The PWA manifest, rendered rather than served from `public/`.
     *
     * It was a static file with the product's name typed into it twice, which
     * made it the one place a rename would silently miss — nothing renders it,
     * so nothing looks wrong until an operator adds the app to her home screen
     * and reads the old name under the icon.
     *
     * No host constraint, for the same reason `machine.php` has none: all four
     * shells include `partials/head.blade.php` and all four therefore ask for
     * `/site.webmanifest`. Public, cacheable, and no session — a manifest is an
     * asset that happens to be composed.
     */
    private static function manifest(): void
    {
        Route::get('/site.webmanifest', function () {
            $name = (string) config('product.name');

            return response()->json([
                'name' => $name,
                // Android truncates the home-screen label around 12
                // characters. There is nothing to shorten while the name is
                // one short word, so this is the same string rather than a
                // second one to keep in step.
                'short_name' => $name,
                'icons' => [
                    ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                    ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ],
                /*
                 * Mirrors `--paper` in tokens.css, the same way the
                 * `theme-color` meta in `partials/head.blade.php` does. Neither
                 * a JSON body nor an HTML attribute can read a CSS variable, so
                 * both are literals; `check-design-tokens.mjs` pins them.
                 */
                'theme_color' => '#FCFBF9',
                'background_color' => '#FCFBF9',
                'display' => 'standalone',
            ])->header('Content-Type', 'application/manifest+json');
        })->name('site.webmanifest');
    }

    /**
     * Each surface carries `web` plus its own middleware group, so a route
     * added to a surface inherits that surface's rate limit and guards without
     * anyone having to remember them.
     */
    private static function surface(Surface $surface, string $file): void
    {
        $route = Route::middleware(['web', "surface.{$surface->value}"]);

        if (Surface::routingBySubdomain() && $surface->host() !== null) {
            $route = $route->domain($surface->host());
        } elseif ($surface->pathPrefix() !== '') {
            $route = $route->prefix($surface->pathPrefix());
        }

        $route->group($file);
    }

    /**
     * Every error page, reachable, outside production.
     *
     * The same shape and the same gate as the component gallery above, for the
     * same reason: a state you cannot open is a state nobody looks at, and the
     * error pages are six screens that by definition never appear when you want
     * them to. `/dev/errors/503` is how they get reviewed at three widths, and
     * how the screenshot suite covers them at all.
     *
     * It aborts rather than rendering the view directly, so what you see is the
     * whole real path — the exception handler, `renderHttpException`, the
     * namespaced `errors::{status}` lookup and the view composer — rather than
     * a template rendered in isolation. That distinction is not academic: the
     * composer was registered for `errors.*` only, which matches by hand and
     * never through the handler, and a preview that skipped the handler would
     * have shown a perfect page while every browser got the stock one.
     */
    private static function errorPreviews(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $route = Route::middleware('web');

        if (Surface::routingBySubdomain() && Surface::App->host() !== null) {
            $route = $route->domain(Surface::App->host());
        }

        $route->get('/dev/errors/{status}', function (string $status) {
            abort(in_array((int) $status, [403, 404, 419, 429, 500, 503], true) ? (int) $status : 404);
        })->name('dev.errors');
    }

    /**
     * The component gallery. Never registered in production, and served from
     * the app surface so it inherits that surface's session and headers.
     */
    private static function gallery(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $route = Route::middleware('web');

        if (Surface::routingBySubdomain() && Surface::App->host() !== null) {
            $route = $route->domain(Surface::App->host());
        }

        $route->get('/dev/components', ComponentGalleryController::class)
            ->name('dev.components');
    }
}
