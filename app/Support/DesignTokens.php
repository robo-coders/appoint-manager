<?php

namespace App\Support;

/**
 * The design tokens, as a string, for the two places that cannot link a
 * stylesheet.
 *
 * The error pages are the whole reason this exists. A 503 renders while the
 * database is down and, during a deploy, while the Vite manifest is being
 * replaced — so `@vite()` on an error page is a page that 500s exactly when it
 * is most needed. The mail templates have the opposite problem: an email client
 * will not fetch a stylesheet at all.
 *
 * The alternative was a second copy of the palette written out by hand, which
 * is the hazard `check:design` was built to catch elsewhere (the mockups, the
 * `theme-color` meta, the web manifest). Reading the one file instead means
 * there is nothing to drift.
 *
 * Reading from disk is safe in every state these pages are for: the view itself
 * is a file on the same disk, so if this read fails there is no page either
 * way. It is memoised per process, and it falls back to an empty string rather
 * than throwing — an unstyled error page is a bad day; a fatal inside the error
 * handler is an infinite one.
 */
final class DesignTokens
{
    private static ?string $root = null;

    /**
     * The declarations inside `:root { … }` in `resources/css/tokens.css`.
     *
     * Only `:root`. The density blocks are for surfaces that have a stylesheet;
     * an error page and an email are neither.
     */
    public static function root(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }

        $path = resource_path('css/tokens.css');
        $css = is_readable($path) ? (string) file_get_contents($path) : '';

        if (! preg_match('/:root\s*\{(.*?)\n\}/s', $css, $matches)) {
            return self::$root = '';
        }

        // Comments carry the reasoning, which is worth a lot in the file and
        // nothing inside a <style> tag on an error page.
        $declarations = preg_replace('#/\*.*?\*/#s', '', $matches[1]);

        return self::$root = trim(preg_replace('/\n\s*\n/', "\n", (string) $declarations));
    }

    /** One named value, e.g. `value('ink')` → `#181714`. For inline email styles. */
    public static function value(string $name): string
    {
        preg_match('/--'.preg_quote($name, '/').':\s*([^;]+);/', self::root(), $matches);

        return trim($matches[1] ?? '') ?: self::fromBlock('mail-dark', $name);
    }

    /**
     * A value from a non-`:root` block, by selector.
     *
     * The email dark palette lives in `[data-scheme='mail-dark']` rather than in
     * `:root`, because `check:design` asserts the mockups carry every `:root`
     * token and a mockup of the dashboard has no business restating an email's
     * dark colours. Same file, so still one source.
     */
    private static function fromBlock(string $scheme, string $name): string
    {
        $path = resource_path('css/tokens.css');
        $css = is_readable($path) ? (string) file_get_contents($path) : '';

        if (! preg_match("/\\[data-scheme='".preg_quote($scheme, '/')."'\\]\\s*\\{(.*?)\\}/s", $css, $block)) {
            return '';
        }

        preg_match('/--'.preg_quote($name, '/').':\s*([^;]+);/', $block[1], $matches);

        return trim($matches[1] ?? '');
    }
}
