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

        self::gallery();
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
