<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * An iCalendar document, built by hand.
 *
 * RFC 5545 is a small format with several sharp edges, and every one of them
 * below is a thing that makes a real calendar client refuse the whole file
 * rather than skip the offending line:
 *
 *   - **CRLF, always.** `\n` alone is not a line break in iCalendar. iOS is the
 *     strictest about this and shows "unsupported calendar" for the file.
 *   - **Folding at 75 octets, not 75 characters.** A continuation line starts
 *     with one space, and the count is bytes — folding mid-way through a UTF-8
 *     sequence produces two invalid bytes. `fold()` below counts octets and only
 *     ever breaks between whole characters.
 *   - **Escaping.** Backslash, semicolon and comma are structural in a text
 *     value and a literal newline has to be written `\n`. A customer called
 *     "Smith, J" is enough to break an unescaped SUMMARY.
 *   - **UTC, with a Z.** Every timestamp here is written as a UTC basic-format
 *     stamp, so the file carries no VTIMEZONE and cannot be wrong about British
 *     Summer Time. The client renders it in the reader's own zone, which is what
 *     a staff member wants.
 *   - **A stable UID.** Same booking, same UID, for the life of the booking —
 *     otherwise every poll adds a duplicate event rather than updating the one
 *     that is there. `SEQUENCE` is what tells the client this is a newer version
 *     of the same event.
 *
 * `X-WR-CALNAME` and `X-WR-TIMEZONE` are not in the RFC and are read by every
 * client that matters: without a name the subscription shows up as the URL.
 * `REFRESH-INTERVAL` and `X-PUBLISHED-TTL` are the polite way to ask for a poll
 * frequency; a client is free to ignore both, which is why the feed is also
 * cheap to serve.
 */
final class ICalendar
{
    /** @var list<string> */
    private array $lines = [];

    public function __construct(string $calendarName, string $timezone)
    {
        $this->lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            // The product identifier. Free-form, and conventionally reverse
            // domain plus product plus version.
            'PRODID:-//'.config('product.name').'//Staff calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($calendarName),
            'X-WR-TIMEZONE:'.$timezone,
            // Ask for a poll every fifteen minutes. The feed is generated per
            // request and the response carries a five-minute cache header, so a
            // client that ignores this costs a query.
            'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
            'X-PUBLISHED-TTL:PT15M',
        ];
    }

    /**
     * One event.
     *
     * `$uid` must be stable for the life of the thing it describes, and unique
     * across every calendar the reader subscribes to — hence the host in it.
     */
    public function event(
        string $uid,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        string $summary,
        ?string $description = null,
        ?CarbonInterface $updatedAt = null,
    ): self {
        $this->lines[] = 'BEGIN:VEVENT';
        $this->lines[] = 'UID:'.$uid;
        $this->lines[] = 'DTSTAMP:'.self::stamp($updatedAt ?? now());
        $this->lines[] = 'DTSTART:'.self::stamp($startsAt);
        $this->lines[] = 'DTEND:'.self::stamp($endsAt);
        $this->lines[] = 'SUMMARY:'.self::escape($summary);

        if (filled($description)) {
            $this->lines[] = 'DESCRIPTION:'.self::escape($description);
        }

        /*
         * `SEQUENCE` from the row's own `updated_at`. A counter would need a
         * column; a timestamp the booking already has is monotonic per booking,
         * which is all the field requires, and it means a rescheduled
         * appointment updates in the client rather than sitting at the old time.
         */
        $this->lines[] = 'SEQUENCE:'.($updatedAt?->getTimestamp() ?? 0);
        $this->lines[] = 'STATUS:CONFIRMED';
        // Nobody is being invited: this is a published feed, and a
        // `TRANSP:OPAQUE` event is one that marks the reader busy.
        $this->lines[] = 'TRANSP:OPAQUE';
        $this->lines[] = 'END:VEVENT';

        return $this;
    }

    public function render(): string
    {
        $lines = [...$this->lines, 'END:VCALENDAR'];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * RFC 5545 §3.3.11: a TEXT value escapes backslash, semicolon and comma,
     * and writes a newline as a literal `\n`. Backslash first, or the escapes
     * added afterwards get escaped in turn.
     */
    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\;', '\\,'],
            $value,
        );
    }

    /** UTC, basic format, with the Z. `20260910T090000Z`. */
    private static function stamp(CarbonInterface $when): string
    {
        return $when->clone()->utc()->format('Ymd\THis\Z');
    }

    /**
     * Fold to 75 octets, breaking only between whole characters.
     *
     * The continuation marker is one space, and it counts towards the 75 — so
     * every line after the first carries 74 octets of payload.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';
        $limit = 75;

        // `mb_str_split` so a multi-byte character is never cut in half; the
        // budget is still counted in bytes, which is what the RFC specifies.
        foreach (mb_str_split($line) as $character) {
            if (strlen($current) + strlen($character) > $limit) {
                $out .= $current."\r\n ";
                $current = '';
                $limit = 74;
            }

            $current .= $character;
        }

        return $out.$current;
    }
}
