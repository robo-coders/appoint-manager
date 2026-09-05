<?php

namespace App\Support;

use App\Http\Controllers\Dev\ComponentGalleryController;
use App\Http\Controllers\MarketingController;
use Illuminate\Http\Request;
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
        self::robots();
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
     * `robots.txt`, once, for all four hostnames.
     *
     * There used to be a `public/robots.txt`, and because a file in the document
     * root is served by nginx before the request reaches PHP, it was the answer
     * for every hostname — one static file, one document root, four hosts. It
     * read:
     *
     *     User-agent: *
     *     Disallow:
     *
     * which is "crawl everything", and what it invited a crawler into included
     * `app.` (the operator app), `admin.` (the console) and every tenant's
     * booking page. It also named no sitemap, so the marketing site's — the one
     * surface that *wants* crawling — was never advertised at all. The file is
     * deleted and this is what answers instead.
     *
     * **One route, not one per surface.** `RouteCollection` keys a route by
     * method and URI, and a second registration on the same key replaces the
     * first rather than sitting behind it: with subdomain routing off, marketing
     * and app share a host *and* an empty prefix, so a marketing `/robots.txt`
     * and a global `/robots.txt` are the same key and whichever is grouped last
     * wins silently. That is not a thing to leave to registration order, so
     * there is one route and it decides.
     *
     * The decision, in both modes:
     *
     *   - **Subdomain routing on.** Only the marketing host is crawlable.
     *     `app.`, `book.` and `admin.` get `Disallow: /`.
     *   - **Subdomain routing off.** There is one host, marketing is at `/`, and
     *     `Surface::current` answers `App` for a root path because it cannot
     *     tell the two apart — they are the same host. So the crawlable answer
     *     is given to everything except the two surfaces that *do* have a path
     *     prefix, `book/` and `admin/`. Locally that is right for the same
     *     reason it is right in production: the pages under `/` are the
     *     marketing site.
     *
     * `app.` and `admin.` are behind auth and a crawler gets a redirect rather
     * than content, so the disallow is belt as well as braces — but `robots.txt`
     * is what keeps the *URLs* out of an index, and the console's URLs are not
     * something to publish.
     *
     * `book.` is `Disallow: /` too, and that is a decision rather than an
     * oversight: a tenant's booking page is public and could reasonably be
     * indexed, but nothing on it is written for search, every slug sits under one
     * shared apex whose reputation no single salon controls, and a half-empty
     * availability grid is a poor first result for a salon's name. Turning it on
     * is a per-tenant question about a page whose canonical home is the salon's
     * own site, and it wants answering deliberately rather than by a file nobody
     * had read.
     */
    private static function robots(): void
    {
        Route::middleware('web')->get('/robots.txt', function (Request $request) {
            $surface = Surface::current($request->getHost(), $request->path());

            $crawlable = Surface::routingBySubdomain()
                ? $surface === Surface::Marketing
                : ! in_array($surface, [Surface::Book, Surface::Admin], true);

            if ($crawlable) {
                return app(MarketingController::class)->robots();
            }

            return response("User-agent: *\nDisallow: /\n", 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        })->name('robots');
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
