<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Assembly;
use App\Repository\AbsenceRepository;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importiert Abwesenheiten aus einer ICS-Kalender-URL.
 *
 * Parst iCal-Einträge (VEVENT) und legt fehlende Abwesenheiten
 * für die zugehörige Person an.
 */
class IcsImportService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PersonRepository $personRepository,
        private readonly AbsenceRepository $absenceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function importFromAssembly(Assembly $assembly): int
    {
        $url = $assembly->getTeamupCalendarUrl();
        if ($url === null || $url === '') {
            throw new \InvalidArgumentException('No TeamUp ICS URL configured.');
        }

        $response = $this->httpClient->request('GET', $url);
        $icsContent = $response->getContent();

        $events = $this->parseIcs($icsContent);
        $persons = $this->personRepository->findByAssembly($assembly->getId());
        $personMap = [];
        foreach ($persons as $person) {
            $personMap[mb_strtolower($person->getName())] = $person;
        }

        $imported = 0;
        foreach ($events as $event) {
            $title = trim($event['summary'] ?? '');
            if ($title === '') {
                continue;
            }

            $person = $personMap[mb_strtolower($title)] ?? null;
            if ($person === null) {
                continue;
            }

            $startDate = $event['dtstart'] ?? null;
            $endDate = $event['dtend'] ?? null;
            if ($startDate === null || $endDate === null) {
                continue;
            }

            $existing = $this->absenceRepository->findAbsencesForPersonOnDate($person->getId(), $startDate);
            if (!empty($existing)) {
                continue;
            }

            $absence = new Absence();
            $absence->setPerson($person);
            $absence->setStartDate($startDate);
            $absence->setEndDate($endDate);
            $absence->setNote('TeamUp: ' . $title);

            $this->entityManager->persist($absence);
            $imported++;
        }

        $this->entityManager->flush();
        return $imported;
    }

    private function parseIcs(string $content): array
    {
        $events = [];
        $current = null;

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $unfolded = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
                if (!empty($unfolded)) {
                    $unfolded[count($unfolded) - 1] .= ltrim($line);
                }
            } else {
                $unfolded[] = $line;
            }
        }

        foreach ($unfolded as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = [];
                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($current !== null) {
                    $events[] = $current;
                }
                $current = null;
                continue;
            }
            if ($current === null) {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $key = strtoupper(substr($line, 0, $colonPos));
            $value = substr($line, $colonPos + 1);

            $semicolonPos = strpos($key, ';');
            $baseKey = $semicolonPos !== false ? substr($key, 0, $semicolonPos) : $key;
            $params = $semicolonPos !== false ? substr($key, $semicolonPos + 1) : '';

            if ($baseKey === 'DTSTART' || $baseKey === 'DTEND') {
                $date = $this->parseIcsDate($value, $params);
                if ($date !== null) {
                    $current[strtolower($baseKey)] = $date;
                }
            } elseif ($baseKey === 'SUMMARY') {
                $current['summary'] = $this->unescapeIcsText($value);
            } elseif ($baseKey === 'UID') {
                $current['uid'] = $value;
            }
        }

        return $events;
    }

    private function parseIcsDate(string $value, string $params): ?\DateTimeImmutable
    {
        $value = trim($value);
        try {
            if (strlen($value) === 8 && ctype_digit($value)) {
                // All-day event: YYYYMMDD
                return new \DateTimeImmutable($value);
            }
            if (str_ends_with($value, 'Z')) {
                // Date-time UTC: YYYYMMDDTHHmmssZ — truncate to date only
                return new \DateTimeImmutable(substr($value, 0, 8));
            }
            if (strlen($value) >= 15 && $value[8] === 'T') {
                return new \DateTimeImmutable(substr($value, 0, 8));
            }
        } catch (\Exception) {
            return null;
        }
        return null;
    }

    private function unescapeIcsText(string $value): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;'], ["\n", "\n", ',', ';'], $value);
    }
}
