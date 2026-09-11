<?php

namespace App\Support;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

final class MarketingSitemap
{
    /** @var list<string> */
    private const NOT_PAGES = [
        'marketing.sitemap',
        'marketing.llms',
    ];

    /** @var list<string> */
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

    /** @return list<array{name: string, url: string}> */
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
