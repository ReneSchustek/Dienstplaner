<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;

/**
 * Erzeugt eine ICS-Datei (iCalendar) aus einer Liste von Zuteilungen.
 *
 * Keine externe Abhängigkeit – reines String-Building nach RFC 5545.
 */
class IcsGeneratorService
{
    public function generateForAssignments(array $assignments, string $assemblyName): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Dienstplaner//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($assignments as $assignment) {
            $lines = array_merge($lines, $this->buildVEvent($assignment, $assemblyName));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function buildVEvent(Assignment $assignment, string $assemblyName): array
    {
        $date     = $assignment->getDay()->getDate();
        $task     = $assignment->getTask();
        $dept     = $task->getDepartment()->getName();
        $dateStr  = $date->format('Ymd');
        $nextDay  = $date->modify('+1 day')->format('Ymd');
        $uid      = 'assignment-' . $assignment->getId() . '@dienstplaner';
        $summary  = $this->escape($task->getName());
        $desc     = $this->escape($assemblyName . ' – ' . $dept);

        return [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTART;VALUE=DATE:' . $dateStr,
            'DTEND;VALUE=DATE:' . $nextDay,
            'SUMMARY:' . $summary,
            'DESCRIPTION:' . $desc,
            'END:VEVENT',
        ];
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ',', ';', "\n"], ['\\\\', '\\,', '\\;', '\\n'], $value);
    }
}
