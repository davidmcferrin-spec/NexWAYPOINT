<?php

declare(strict_types=1);

namespace NexWaypoint\Calendar;

/**
 * Minimal RFC 5545 VCALENDAR writer (no Composer dependency).
 * Tuned for Outlook internet-calendar subscribe (headers + folding + text).
 */
final class IcsBuilder
{
    /**
     * @param list<IcsEvent> $events
     */
    public function build(string $calendarName, array $events, ?string $prodId = null): string
    {
        $prodId ??= '-//NexWAYPOINT//Calendar//EN';
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . $this->text($prodId),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->text($calendarName),
        ];

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($events as $event) {
            $lines = array_merge($lines, $this->eventLines($event, $now));
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @return list<string>
     */
    private function eventLines(IcsEvent $event, \DateTimeImmutable $nowUtc): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $this->text($event->uid),
            'DTSTAMP:' . $nowUtc->format('Ymd\THis\Z'),
        ];

        if ($event->allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $this->dateOnly($event->dtStart);
            $lines[] = 'DTEND;VALUE=DATE:' . $this->dateOnly($event->dtEnd);
        } else {
            $lines[] = 'DTSTART:' . $this->utcStamp($event->dtStart);
            $lines[] = 'DTEND:' . $this->utcStamp($event->dtEnd);
        }

        $lines[] = 'SUMMARY:' . $this->text($event->summary);
        if ($event->description !== null && $event->description !== '') {
            $lines[] = 'DESCRIPTION:' . $this->text($event->description);
        }
        if ($event->location !== null && $event->location !== '') {
            $lines[] = 'LOCATION:' . $this->text($event->location);
        }
        if ($event->categories !== []) {
            $lines[] = $this->categoriesLine($event->categories);
        }
        $lines[] = 'STATUS:' . $this->text($event->status);
        $lines[] = 'SEQUENCE:' . max(0, $event->sequence);
        if ($event->lastModified !== null) {
            $mod = $event->lastModified->setTimezone(new \DateTimeZone('UTC'));
            $lines[] = 'LAST-MODIFIED:' . $mod->format('Ymd\THis\Z');
        }
        $lines[] = 'TRANSP:' . ($event->allDay ? 'TRANSPARENT' : 'OPAQUE');
        $lines[] = 'END:VEVENT';

        return array_map([$this, 'fold'], $lines);
    }

    /**
     * @param list<string> $categories
     */
    private function categoriesLine(array $categories): string
    {
        // Commas separate categories; escape only inside each value.
        $parts = [];
        foreach ($categories as $category) {
            $category = trim($category);
            if ($category === '') {
                continue;
            }
            $parts[] = $this->text($category);
        }
        return 'CATEGORIES:' . implode(',', $parts);
    }

    private function text(string $value): string
    {
        $value = $this->outlookSafe($value);
        // Escape backslashes first so newline/`\,`/`\;` markers stay single-escaped.
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
        $value = str_replace([',', ';'], ['\\,', '\\;'], $value);
        return $value;
    }

    /**
     * Normalize punctuation Outlook's subscribe parser often rejects, without
     * stripping accented city/person names.
     */
    private function outlookSafe(string $value): string
    {
        return strtr($value, [
            '·' => '-',
            '•' => '-',
            '→' => '->',
            '←' => '<-',
            '—' => '-',
            '–' => '-',
            '’' => "'",
            '‘' => "'",
            '“' => '"',
            '”' => '"',
            "\u{00A0}" => ' ',
            "\u{200B}" => '',
            "\u{200C}" => '',
            "\u{200D}" => '',
            "\u{FEFF}" => '',
        ]);
    }

    private function dateOnly(string $ymd): string
    {
        $ymd = trim($ymd);
        if (preg_match('/^\d{8}$/', $ymd)) {
            return $ymd;
        }
        try {
            return (new \DateTimeImmutable($ymd))->format('Ymd');
        } catch (\Exception) {
            return preg_replace('/\D/', '', $ymd) ?: '19700101';
        }
    }

    private function utcStamp(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
            return $value;
        }
        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        } catch (\Exception) {
            return gmdate('Ymd\THis\Z');
        }
    }

    /**
     * RFC 5545 §3.1: fold at 75 octets; never split a UTF-8 codepoint.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $cut = $this->utf8SafeCut($line, 75);
        $out = substr($line, 0, $cut);
        $rest = substr($line, $cut);
        while ($rest !== '') {
            $cut = $this->utf8SafeCut($rest, 74);
            $out .= "\r\n " . substr($rest, 0, $cut);
            $rest = substr($rest, $cut);
        }
        return $out;
    }

    private function utf8SafeCut(string $value, int $maxOctets): int
    {
        $len = strlen($value);
        if ($len <= $maxOctets) {
            return $len;
        }

        $cut = $maxOctets;
        while ($cut > 0 && (ord($value[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }

        if ($cut === 0) {
            // Pathological: single codepoint longer than the fold limit.
            $cut = 1;
            while ($cut < $len && (ord($value[$cut]) & 0xC0) === 0x80) {
                $cut++;
            }
        }

        return $cut;
    }
}
