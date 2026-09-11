<?php

namespace App\Support;

final class DesignTokens
{
    private static ?string $root = null;

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

        $declarations = preg_replace('#/\*.*?\*/#s', '', $matches[1]);

        return self::$root = trim(preg_replace('/\n\s*\n/', "\n", (string) $declarations));
    }

    public static function value(string $name): string
    {
        preg_match('/--'.preg_quote($name, '/').':\s*([^;]+);/', self::root(), $matches);

        return trim($matches[1] ?? '') ?: self::fromBlock('mail-dark', $name);
    }

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
