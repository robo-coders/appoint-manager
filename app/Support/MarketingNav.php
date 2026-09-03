<?php

namespace App\Support;

use App\Models\Vertical;
use Illuminate\Support\Facades\Route;

/**
 * The vertical pages the marketing site has live, and what they are called.
 *
 * The header and the footer are shared by every page and must stay true for
 * every vertical the product adds, so neither is allowed to type a trade's name
 * out. `/dog-grooming` appears in the footer as "Dog grooming" because that is
 * the `label` on the groomer row in `verticals`, which is the same string the
 * operator app and the booking page use. Nothing here spells it.
 *
 * Adding `/barbers` is one line below, one route, and one copy file. The header
 * and footer do not change, and neither does this class beyond that line.
 */
final class MarketingNav
{
    /**
     * Vertical key => the named route for its page, in the order they are listed.
     *
     * The `barber` vertical exists in the database and has no page yet, which is
     * the case this map exists to keep honest: a vertical the product supports
     * is not the same thing as a vertical we have written a page for, and the
     * footer must only link to pages that exist.
     *
     * @var array<string, string>
     */
    private const PAGES = [
        'groomer' => 'marketing.dog-grooming',
    ];

    /**
     * @return list<array{label: string, href: string, route: string}>
     */
    public static function verticalPages(): array
    {
        $pages = [];

        foreach (self::PAGES as $key => $name) {
            // A named route that does not exist would 500 the footer on every
            // page of the site, which is a poor way to find out a route was
            // renamed.
            if (! Route::has($name)) {
                continue;
            }

            /*
             * The label is a database read, and this runs in the footer of
             * every page on a surface that otherwise touches no database at
             * all. `rescue` keeps that property: with the database down the
             * link is left out and the rest of the site still serves, rather
             * than the whole marketing surface 500ing because a footer could
             * not look up a word. Dropping the link is the honest failure —
             * the alternative is spelling the trade's name here as a fallback,
             * which is the thing this class exists to avoid.
             */
            $label = rescue(fn () => (string) Vertical::definitionFor($key)['label'], null, false);

            if ($label === null || $label === '') {
                continue;
            }

            $pages[] = [
                'label' => $label,
                'href' => route($name),
                'route' => $name,
            ];
        }

        return $pages;
    }
}
