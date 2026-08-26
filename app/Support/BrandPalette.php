<?php

namespace App\Support;

use RuntimeException;

/**
 * The six tenant brand presets, read from `resources/css/tokens.css`.
 *
 * The values are NOT restated here, and that is the whole point. There are now
 * four places that need to agree about what "forest" is — this class, the
 * stylesheet, the contrast checker and the booking page — and every previous
 * attempt at that in this codebase drifted. `check-contrast.mjs` already learnt
 * this lesson: it used to hold its own copy of the presets, which meant the
 * values that shipped were never the values that were checked.
 *
 * So PHP reads the NAMES out of the stylesheet and never the colours. A preset
 * reaches the browser as `--brand: var(--brand-forest)` — a reference, not a
 * hex — so the only file in the repository that says what forest looks like is
 * tokens.css. Changing a preset there changes it everywhere, with nothing to
 * keep in step and nothing to forget.
 *
 * There is deliberately no free hex field anywhere in the product. Six presets
 * that each clear 4.5:1 against white button text is a promise we can keep; a
 * colour picker is a promise that someone ships neon yellow on white and it is
 * our product that looks broken.
 */
final class BrandPalette
{
    /**
     * The presets the product is expected to offer. Read as an assertion, not
     * as data: if tokens.css and this list disagree, something has been renamed
     * in one place and not the other, and failing loudly is the correct answer.
     *
     * @var list<string>
     */
    private const EXPECTED = ['forest', 'plum', 'navy', 'ochre', 'slate', 'clay'];

    /** @var list<string>|null */
    private static ?array $names = null;

    /**
     * Preset names, in the order tokens.css declares them.
     *
     * Memoised per process rather than cached: a cached palette that survives an
     * edit to tokens.css is a confusing afternoon, and reading one small file is
     * not worth that.
     *
     * @return list<string>
     */
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

        // `--brand` and `--brand-fg` are the resolved values, not presets.
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

    /**
     * Is this a colour a tenant is allowed to have chosen?
     *
     * Every path that puts a brand colour into a page goes through here. The
     * value ends up inside a `style` attribute as `var(--brand-{$name})`, so an
     * unvalidated one is an HTML injection with extra steps — which is the
     * other reason the six are a fixed list rather than a text field.
     */
    public static function isPreset(?string $name): bool
    {
        return $name !== null && in_array($name, self::names(), true);
    }

    /**
     * The CSS custom property a preset resolves to, or null for "no choice".
     *
     * Null is the common case and the correct one: a tenant that has not picked
     * a colour gets `--brand`'s own default, which tokens.css sets to ink.
     */
    public static function variable(?string $name): ?string
    {
        return self::isPreset($name) ? "var(--brand-{$name})" : null;
    }

    /**
     * Reset the memoised palette. For tests that rewrite tokens.css.
     */
    public static function flush(): void
    {
        self::$names = null;
    }
}
