<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class IcalFeedBuilder
{
    public const MODE_FULL = 'full';

    public const MODE_BUSY_ONLY = 'busy_only';

    private const OCTET_LIMIT = 75;

    private const CONTINUATION_LIMIT = 74;

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_FULL, self::MODE_BUSY_ONLY];
    }

    /** @param  Collection<int, Booking>  $bookings */
    public function build(string $calendarName, string $timezone, Collection $bookings, string $contentMode): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.config('product.name').'//Calendar Sync//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($calendarName),
            'X-WR-TIMEZONE:'.$timezone,
        ];

        $stamp = $this->stamp(now());

        foreach ($bookings as $booking) {
            $lines = [...$lines, ...$this->event($booking, $contentMode, $stamp)];
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /** @return list<string> */
    private function event(Booking $booking, string $contentMode, string $stamp): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$booking->getKey().'@'.config('calendar_sync.uid_domain'),
            'DTSTAMP:'.$stamp,
            'DTSTART:'.$this->stamp($booking->starts_at),
            'DTEND:'.$this->stamp($booking->ends_at),
            'SEQUENCE:'.(int) $booking->calendar_sequence,
        ];

        if ($contentMode === self::MODE_BUSY_ONLY) {
            $lines[] = 'SUMMARY:Busy';
        } else {
            $service = $booking->service?->name ?? 'Appointment';
            $staff = $booking->staff?->name;

            $lines[] = 'SUMMARY:'.$this->escape(($booking->customer?->name ?? 'Customer').' — '.$service);
            $lines[] = 'DESCRIPTION:'.$this->escape($staff === null ? $service : $service.' with '.$staff.'.');
        }

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\;', '\\,'],
            $value,
        );
    }

    private function stamp(CarbonInterface $when): string
    {
        return $when->clone()->utc()->format('Ymd\THis\Z');
    }

    private function fold(string $line): string
    {
        if (strlen($line) <= self::OCTET_LIMIT) {
            return $line;
        }

        $folded = '';
        $current = '';
        $limit = self::OCTET_LIMIT;

        foreach (mb_str_split($line) as $character) {
            if (strlen($current) + strlen($character) > $limit) {
                $folded .= $current."\r\n ";
                $current = '';
                $limit = self::CONTINUATION_LIMIT;
            }

            $current .= $character;
        }

        return $folded.$current;
    }
}
