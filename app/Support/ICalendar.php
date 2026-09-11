<?php

namespace App\Support;

use Carbon\CarbonInterface;

final class ICalendar
{
    /** @var list<string> */
    private array $lines = [];

    public function __construct(string $calendarName, string $timezone)
    {
        $this->lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.config('product.name').'//Staff calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($calendarName),
            'X-WR-TIMEZONE:'.$timezone,
            'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
            'X-PUBLISHED-TTL:PT15M',
        ];
    }

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

        $this->lines[] = 'SEQUENCE:'.($updatedAt?->getTimestamp() ?? 0);
        $this->lines[] = 'STATUS:CONFIRMED';
        $this->lines[] = 'TRANSP:OPAQUE';
        $this->lines[] = 'END:VEVENT';

        return $this;
    }

    public function render(): string
    {
        $lines = [...$this->lines, 'END:VCALENDAR'];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\;', '\\,'],
            $value,
        );
    }

    private static function stamp(CarbonInterface $when): string
    {
        return $when->clone()->utc()->format('Ymd\THis\Z');
    }

    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';
        $limit = 75;

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
