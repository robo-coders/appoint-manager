<?php

namespace App\Support;

use RuntimeException;

final class BrandPalette
{
    /** @var list<string> */
    private const EXPECTED = ['forest', 'plum', 'navy', 'ochre', 'slate', 'clay'];

    /** @var list<string>|null */
    private static ?array $names = null;

    /** @return list<string> */
    public static function names(): array
    {
        if (self::$names !== null) {
            return self::$names;
        }

        $path = resource_path('css/tokens.css');
        $css = @file_get_contents($path);

        if ($css === false) {
            throw new RuntimeException("Cannot read the design tokens at {$path}. The brand presets are defined there.");
        }

        preg_match_all('/--brand-(?!fg\b)([a-z]+)\s*:/', $css, $matches);

        /** @var list<string> $found */
        $found = array_values(array_unique($matches[1]));

        $missing = array_diff(self::EXPECTED, $found);

        if ($missing !== []) {
            throw new RuntimeException(
                'tokens.css is missing brand preset(s): '.implode(', ', $missing).'. '
                .'The presets are defined in the stylesheet and read from there.'
            );
        }

        return self::$names = $found;
    }

    public static function isPreset(?string $name): bool
    {
        return $name !== null && in_array($name, self::names(), true);
    }

    public static function variable(?string $name): ?string
    {
        return self::isPreset($name) ? "var(--brand-{$name})" : null;
    }

    public static function flush(): void
    {
        self::$names = null;
    }
}
