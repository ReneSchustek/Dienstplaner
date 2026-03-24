<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Assembly;
use App\Repository\AbsenceRepository;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importiert Abwesenheiten aus einem TeamUp-Kalender.
 *
 * Liest ICS-Daten via TeamUpClient und ordnet Ereignisse anhand des
 * Namens den Personen der Versammlung zu.
 */
class TeamUpImportService
{
    public function __construct(
        private readonly TeamUpClient $teamUpClient,
        private readonly PersonRepository $personRepository,
        private readonly AbsenceRepository $absenceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function importAbsencesForAssembly(Assembly $assembly, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $events = $this->teamUpClient->fetchEvents($from, $to);
        $persons = $this->personRepository->findByAssembly($assembly->getId());
        $personMap = $this->buildPersonNameMap($persons);

        $imported = 0;
        foreach ($events as $event) {
            $personName = $this->extractPersonName($event);
            if ($personName === null || !isset($personMap[$personName])) {
                continue;
            }

            $person = $personMap[$personName];
            $startDate = new \DateTimeImmutable($event['start_dt']);
            $endDate = new \DateTimeImmutable($event['end_dt']);

            if ($this->absenceAlreadyExists($person->getId(), $startDate, $endDate)) {
                continue;
            }

            $absence = new Absence();
            $absence->setPerson($person);
            $absence->setStartDate($startDate);
            $absence->setEndDate($endDate);
            $absence->setNote('Import');

            $this->entityManager->persist($absence);
            $imported++;
        }

        $this->entityManager->flush();
        return $imported;
    }

    private function buildPersonNameMap(array $persons): array
    {
        $map = [];
        foreach ($persons as $person) {
            $map[$person->getName()] = $person;
        }
        return $map;
    }

    private function extractPersonName(array $event): ?string
    {
        return isset($event['title']) ? trim($event['title']) : null;
    }

    private function absenceAlreadyExists(int $personId, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $existing = $this->absenceRepository->findAbsencesForPersonOnDate($personId, $start);
        return !empty($existing);
    }
}
