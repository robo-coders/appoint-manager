<?php

namespace App\Support;

use App\Http\Controllers\Dev\ComponentGalleryController;
use App\Http\Controllers\MarketingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class SurfaceRoutes
{
    public static function register(): void
    {
        self::surface(Surface::Marketing, __DIR__.'/../../routes/marketing.php');
        self::surface(Surface::App, __DIR__.'/../../routes/app.php');
        self::surface(Surface::Book, __DIR__.'/../../routes/book.php');
        self::surface(Surface::Admin, __DIR__.'/../../routes/admin.php');

        Route::middleware('web')->group(__DIR__.'/../../routes/machine.php');

        self::manifest();
        self::robots();
        self::gallery();
        self::errorPreviews();
    }

    private static function manifest(): void
    {
        Route::get('/site.webmanifest', function () {
            $name = (string) config('product.name');

            return response()->json([
                'name' => $name,
                'short_name' => $name,
                'icons' => [
                    ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                    ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ],
                'theme_color' => '#FCFBF9',
                'background_color' => '#FCFBF9',
                'display' => 'standalone',
            ])->header('Content-Type', 'application/manifest+json');
        })->name('site.webmanifest');
    }

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
