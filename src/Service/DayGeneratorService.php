<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assembly;
use App\Entity\Day;
use App\Repository\DayRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generiert Planungstage für einen Monat.
 *
 * Erstellt Day-Einträge basierend auf den konfigurierten Wochentagen
 * der Versammlung für den angegebenen Monat.
 */
class DayGeneratorService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DayRepository $dayRepository,
    ) {}

    public function generateDaysForMonth(Assembly $assembly, int $year, int $month): array
    {
        $days = [];
        $daysInMonth = (int) (new \DateTimeImmutable("$year-$month-01"))->format('t');

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = new \DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $month, $d));
            $weekday = (int) $date->format('w');

            if (!in_array($weekday, $assembly->getWeekdays(), true)) {
                continue;
            }

            $existing = $this->dayRepository->findByAssemblyAndDate($assembly->getId(), $date);
            if ($existing !== null) {
                $days[] = $existing;
                continue;
            }

            $day = new Day();
            $day->setDate($date);
            $day->setAssembly($assembly);
            $this->entityManager->persist($day);
            $days[] = $day;
        }

        $this->entityManager->flush();
        return $days;
    }
}
