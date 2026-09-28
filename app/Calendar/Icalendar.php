<?php

declare(strict_types=1);

namespace App\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class Icalendar
{
    /** @param Collection<int, array<string, mixed>> $entries */
    public function render(Collection $entries, string $name): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GymSLunity//Vereinskalender//DE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:'.$this->escape($name)];
        foreach ($entries as $entry) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$entry['id'].'@gymslunity';
            $lines[] = 'DTSTAMP:'.now('UTC')->format('Ymd\THis\Z');
            if ($entry['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:'.substr(str_replace('-', '', $entry['starts_at']), 0, 8);
                $lines[] = 'DTEND;VALUE=DATE:'.substr(str_replace('-', '', $entry['ends_at']), 0, 8);
            } else {
                $lines[] = 'DTSTART:'.CarbonImmutable::parse($entry['starts_at'], config('app.timezone'))->utc()->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.CarbonImmutable::parse($entry['ends_at'], config('app.timezone'))->utc()->format('Ymd\THis\Z');
            }
            $lines[] = 'SUMMARY:'.$this->escape($entry['title']);
            if ($entry['location']) {
                $lines[] = 'LOCATION:'.$this->escape($entry['location']);
            }
            if ($entry['description']) {
                $lines[] = 'DESCRIPTION:'.$this->escape($entry['description']);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(fn (string $line): string => $this->fold($line), $lines))."\r\n";
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $value);
    }

    private function fold(string $line): string
    {
        $parts = [];
        $remaining = $line;
        while (strlen($remaining) > 73) {
            $part = mb_strcut($remaining, 0, 73, 'UTF-8');
            $parts[] = $part;
            $remaining = substr($remaining, strlen($part));
        }
        $parts[] = $remaining;

        return implode("\r\n ", $parts);
    }
}
