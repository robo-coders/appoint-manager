<?php

namespace App\Support;

use App\Models\Vertical;
use Illuminate\Support\Facades\Route;

final class MarketingNav
{
    /** @var array<string, string> */
    private const PAGES = [
        'groomer' => 'marketing.dog-grooming',
    ];

    /** @return list<array{label: string, href: string, route: string}> */
    public static function verticalPages(): array
    {
        $pages = [];

        foreach (self::PAGES as $key => $name) {
            if (! Route::has($name)) {
                continue;
            }

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
