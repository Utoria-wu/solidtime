<?php

declare(strict_types=1);

namespace App\Service;

use Carbon\CarbonImmutable;

class HolidayCalendarImport
{
    /**
     * Read all-day, non-recurring VEVENTs. End dates are exclusive.
     *
     * @return array{events: array<string, array{uid_hash: string, title: string, start_date: string, end_date: string, workday: bool}>, skipped: int}
     */
    public function parse(string $contents): array
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $contents = preg_replace('/\n[ \t]/', '', $contents) ?? '';
        if (preg_match('/\ABEGIN:VCALENDAR\n.*\nEND:VCALENDAR\s*\z/is', ltrim($contents, "\xEF\xBB\xBF")) !== 1) {
            return ['events' => [], 'skipped' => 0];
        }

        preg_match_all('/^BEGIN:VEVENT\n(.*?)^END:VEVENT\s*$/ims', $contents, $blocks);
        $events = [];
        $skipped = 0;
        foreach ($blocks[1] as $block) {
            $fields = [];
            foreach (explode("\n", $block) as $line) {
                // Ignore nested alarms and their descriptions.
                if (str_starts_with(strtoupper($line), 'BEGIN:')) {
                    break;
                }
                if (preg_match('/^([A-Z-]+)(?:;[^:]*)?:(.*)$/i', $line, $parts) === 1) {
                    $fields[strtoupper($parts[1])] ??= $parts[2];
                }
            }
            $uid = trim($fields['UID'] ?? '');
            $title = trim(strtr($fields['SUMMARY'] ?? '', ['\\,' => ',', '\\;' => ';', '\\n' => ' ', '\\N' => ' ', '\\\\' => '\\']));
            $start = $this->date($fields['DTSTART'] ?? '');
            $end = isset($fields['DTEND']) ? $this->date($fields['DTEND']) : $start?->addDay();
            if ($uid === '' || $title === '' || ! mb_check_encoding($title, 'UTF-8') || ! $start || ! $end ||
                $end->lte($start) || $end->gt($start->addYear()) || isset($fields['RRULE']) ||
                isset($fields['RDATE']) || isset($fields['EXDATE']) || isset($fields['RECURRENCE-ID']) ||
                isset($fields['DURATION']) || strtoupper($fields['STATUS'] ?? '') === 'CANCELLED') {
                $skipped++;

                continue;
            }

            $hash = hash('sha256', $uid);
            $events[$hash] = [
                'uid_hash' => $hash,
                'title' => mb_substr($title, 0, 160),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'workday' => preg_match('/\bWORK ?DAY\b|补班|上班|工作日|補班|補假上班/iu', $title.' '.($fields['CATEGORIES'] ?? '')) === 1,
            ];
        }

        return ['events' => $events, 'skipped' => $skipped];
    }

    private function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})$/D', $value, $parts) !== 1 ||
            ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, 'UTC');
    }
}
