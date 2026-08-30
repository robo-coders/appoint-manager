<?php

namespace App\Support;

/**
 * What an SMS actually costs.
 *
 * A carrier bills per segment, not per message, and the segment size depends
 * on the alphabet. GSM 03.38 packs seven bits to a character, so 160 fit in
 * one 140-byte payload. One character outside that alphabet — a curly
 * apostrophe pasted from Word, an emoji, the é in Zoë — converts the *whole*
 * message to UCS-2 and the limit drops to 70. Concatenated messages spend six
 * of their septets on a header, so a two-part GSM-7 message is 153 per part
 * and a two-part UCS-2 message is 67.
 *
 * This class is why `sms_cycle_used` is a segment count. See
 * `SmsAllowance::consume()`.
 */
final class SmsSegments
{
    /**
     * GSM 03.38 basic character set, as a lookup.
     *
     * @var array<string, true>|null
     */
    private static ?array $basic = null;

    /**
     * The extension table. Each of these is sent as an escape plus a
     * character, so it costs two septets rather than one.
     *
     * @var list<string>
     */
    private const EXTENDED = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];

    private const GSM_SINGLE = 160;

    private const GSM_CONCAT = 153;

    private const UCS2_SINGLE = 70;

    private const UCS2_CONCAT = 67;

    /**
     * Straighten the punctuation that silently doubles the price of a message.
     *
     * Letters are never touched. An accented name is a customer's actual name
     * and stripping the accent to save a segment is not our decision to make —
     * the cost is reported instead, by `count()`.
     */
    public static function sanitise(string $body): string
    {
        return strtr($body, [
            // Curly quotes. The commonest cause of an unexpected UCS-2 message,
            // because a phone keyboard and a word processor both produce them.
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
            // Dashes and the ellipsis. An em dash is in the copy of this
            // product all over the place and is not in GSM-7.
            "\u{2010}" => '-',
            "\u{2011}" => '-',
            "\u{2012}" => '-',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{2015}" => '-',
            "\u{2212}" => '-',
            "\u{2026}" => '...',
            // Spaces that are not spaces.
            "\u{00A0}" => ' ',
            "\u{2007}" => ' ',
            "\u{2009}" => ' ',
            "\u{200A}" => ' ',
            "\u{202F}" => ' ',
            "\u{FEFF}" => '',
            "\u{200B}" => '',
        ]);
    }

    /**
     * True when every character is in GSM 03.38 and the message can be sent as
     * one septet per character.
     */
    public static function isGsm7(string $body): bool
    {
        foreach (mb_str_split($body) as $char) {
            if (! isset(self::basic()[$char]) && ! in_array($char, self::EXTENDED, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many segments the carrier will bill for this body.
     */
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

    /**
     * 'GSM-7' or 'UCS-2' — which alphabet this body forces.
     */
    public static function encoding(string $body): string
    {
        return self::isGsm7($body) ? 'GSM-7' : 'UCS-2';
    }

    /**
     * Characters remaining before the next segment starts.
     */
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

    /**
     * Everything the dry run and the send log need to say about one body.
     *
     * @return array{segments: int, encoding: string, characters: int, units: int, remaining: int}
     */
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

    /**
     * Build a message, shortening one part of it if the whole will not fit.
     *
     * `Str::limit($body, 160, '')` was what this replaced, and it truncated by
     * character count from the end — which is where every one of these messages
     * keeps its booking link and its opt-out notice. A salon called "Battersea
     * and Clapham Dog Grooming Company" produced a confirmation text with the
     * URL sliced in half, silently, and nothing would have caught it.
     *
     * So the caller names the one part that may be shortened — in practice the
     * salon's own name, the only unbounded string in any of these bodies — and
     * `$render` puts the message back together around it. Everything else is
     * structural and survives.
     *
     * This is a runaway guard and not a formatting rule: with `max_segments` at
     * 3 a real salon name never reaches it. The normal path returns on the
     * first line.
     *
     * @param  callable(string): string  $render
     */
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

    /**
     * Payload size in the alphabet's own units: septets for GSM-7 (extension
     * characters counting twice), UTF-16 code units for UCS-2.
     */
    private static function units(string $body): int
    {
        if (! self::isGsm7($body)) {
            // UCS-2 is billed in 16-bit units, so anything outside the BMP —
            // an emoji — is two.
            return (int) (strlen(mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')) / 2);
        }

        $units = 0;

        foreach (mb_str_split($body) as $char) {
            $units += in_array($char, self::EXTENDED, true) ? 2 : 1;
        }

        return $units;
    }

    /**
     * @return array<string, true>
     */
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
