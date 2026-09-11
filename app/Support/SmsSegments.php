<?php

namespace App\Support;

final class SmsSegments
{
    /** @var array<string, true>|null */
    private static ?array $basic = null;

    /** @var list<string> */
    private const EXTENDED = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];

    private const GSM_SINGLE = 160;

    private const GSM_CONCAT = 153;

    private const UCS2_SINGLE = 70;

    private const UCS2_CONCAT = 67;

    public static function sanitise(string $body): string
    {
        return strtr($body, [
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201A}" => "'",
            "\u{201B}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            "\u{201E}" => '"',
            "\u{201F}" => '"',
            "\u{2032}" => "'",
            "\u{2033}" => '"',
            "\u{2010}" => '-',
            "\u{2011}" => '-',
            "\u{2012}" => '-',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{2015}" => '-',
            "\u{2212}" => '-',
            "\u{2026}" => '...',
            "\u{00A0}" => ' ',
            "\u{2007}" => ' ',
            "\u{2009}" => ' ',
            "\u{200A}" => ' ',
            "\u{202F}" => ' ',
            "\u{FEFF}" => '',
            "\u{200B}" => '',
        ]);
    }

    public static function isGsm7(string $body): bool
    {
        foreach (mb_str_split($body) as $char) {
            if (! isset(self::basic()[$char]) && ! in_array($char, self::EXTENDED, true)) {
                return false;
            }
        }

        return true;
    }

    public static function count(string $body): int
    {
        if ($body === '') {
            return 0;
        }

        $units = self::units($body);
        $gsm = self::isGsm7($body);
        $single = $gsm ? self::GSM_SINGLE : self::UCS2_SINGLE;
        $concat = $gsm ? self::GSM_CONCAT : self::UCS2_CONCAT;

        return $units <= $single ? 1 : (int) ceil($units / $concat);
    }

    public static function encoding(string $body): string
    {
        return self::isGsm7($body) ? 'GSM-7' : 'UCS-2';
    }

    public static function remainingInSegment(string $body): int
    {
        $units = self::units($body);
        $gsm = self::isGsm7($body);
        $segments = self::count($body);

        if ($segments <= 1) {
            return ($gsm ? self::GSM_SINGLE : self::UCS2_SINGLE) - $units;
        }

        return ($segments * ($gsm ? self::GSM_CONCAT : self::UCS2_CONCAT)) - $units;
    }

    /** @return array{segments: int, encoding: string, characters: int, units: int, remaining: int} */
    public static function describe(string $body): array
    {
        return [
            'segments' => self::count($body),
            'encoding' => self::encoding($body),
            'characters' => mb_strlen($body),
            'units' => self::units($body),
            'remaining' => self::remainingInSegment($body),
        ];
    }

    public static function fit(string $shrinkable, callable $render, int $maxSegments): string
    {
        $body = self::sanitise($render($shrinkable));

        if (self::count($body) <= $maxSegments) {
            return $body;
        }

        $chars = mb_str_split($shrinkable);

        while ($chars !== []) {
            array_pop($chars);
            $body = self::sanitise($render(rtrim(implode('', $chars))));

            if (self::count($body) <= $maxSegments) {
                return $body;
            }
        }

        return $body;
    }

    private static function units(string $body): int
    {
        if (! self::isGsm7($body)) {
            return (int) (strlen(mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')) / 2);
        }

        $units = 0;

        foreach (mb_str_split($body) as $char) {
            $units += in_array($char, self::EXTENDED, true) ? 2 : 1;
        }

        return $units;
    }

    /** @return array<string, true> */
    private static function basic(): array
    {
        if (self::$basic !== null) {
            return self::$basic;
        }

        $chars = array_merge(
            mb_str_split('@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ'),
            mb_str_split(' !"#¤%&\'()*+,-./0123456789:;<=>?'),
            mb_str_split('¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§'),
            mb_str_split('¿abcdefghijklmnopqrstuvwxyzäöñüà'),
            ["\n", "\r"],
        );

        return self::$basic = array_fill_keys($chars, true);
    }
}
