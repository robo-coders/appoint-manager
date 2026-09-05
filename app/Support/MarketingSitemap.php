<?php

namespace App\Support;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * The list of marketing URLs, read off the router.
 *
 * It used to be eight `route()` calls typed into `MarketingController::sitemap`,
 * which is the same list twice: once in `routes/marketing.php`, once here. A
 * ninth page added to the routes file and not to that array is a page no crawler
 * is ever told about, and nothing fails — the sitemap is still valid XML, just
 * short. That is the worst shape a check can have.
 *
 * So the router is the source. Every named route beginning `marketing.` that is
 * a plain `GET` with no parameters is a page, minus the three machine files
 * (`sitemap.xml` and `llms.txt`) which are not pages and must not list
 * themselves. Adding `/barbers` puts it in the sitemap and in `llms.txt`
 * with no second edit.
 *
 * The 404 is absent by construction rather than by exclusion: it is rendered
 * from `bootstrap/app.php` and is not a route at all.
 */
final class MarketingSitemap
{
    /**
     * Named routes that exist on the marketing host but are not pages.
     *
     * `contact.send` is excluded by the GET filter rather than by name, so it is
     * not listed here — one exclusion per reason. `robots.txt` is not listed
     * either: it is one route for all four hostnames and is not named
     * `marketing.*`, so the prefix filter has already dropped it.
     *
     * @var list<string>
     */
    private const NOT_PAGES = [
        'marketing.sitemap',
        'marketing.llms',
    ];

    /**
     * The order pages are listed in: the ones we would want read first.
     *
     * A sitemap does not carry priority any more (Google ignores `<priority>`,
     * which is why none is emitted), but `llms.txt` is read top to bottom by
     * something summarising the product, and "what it is" before "the terms" is
     * the difference between a useful summary and a legal one. Anything not
     * named keeps its route order after these.
     *
     * @var list<string>
     */
    private const READING_ORDER = [
        'marketing.home',
        'marketing.how-it-works',
        'marketing.pricing',
        'marketing.dog-grooming',
        'marketing.about',
        'marketing.contact',
        'marketing.privacy',
        'marketing.terms',
    ];

    /**
     * @return list<array{name: string, url: string}>
     */
    public static function pages(): array
    {
        $pages = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'marketing.')) {
                continue;
            }

            if (in_array($name, self::NOT_PAGES, true)) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true) || self::takesParameters($route)) {
                continue;
            }

            $pages[$name] = ['name' => $name, 'url' => route($name)];
        }

        return self::sorted($pages);
    }

    /**
     * @param  array<string, array{name: string, url: string}>  $pages
     * @return list<array{name: string, url: string}>
     */
    private static function sorted(array $pages): array
    {
        $ordered = [];

        foreach (self::READING_ORDER as $name) {
            if (isset($pages[$name])) {
                $ordered[] = $pages[$name];
                unset($pages[$name]);
            }
        }

        return [...$ordered, ...array_values($pages)];
    }

    private static function takesParameters(RoutingRoute $route): bool
    {
        return $route->parameterNames() !== [];
    }
}
