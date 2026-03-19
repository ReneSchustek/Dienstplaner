<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assembly;
use App\Repository\AbsenceRepository;
use App\Repository\AssignmentRepository;
use App\Repository\ExternalTaskRepository;
use App\Repository\PersonRepository;

/**
 * Generiert automatische Planungsvorschläge basierend auf:
 * – Aufgaben-Qualifikation der Personen
 * – Abwesenheiten und externen Aufgaben
 * – Fairness-Score (wenigst eingeplante Person zuerst)
 */
class PlanningProposalService
{
    public function __construct(
        private readonly AssignmentRepository $assignmentRepository,
        private readonly AbsenceRepository $absenceRepository,
        private readonly ExternalTaskRepository $externalTaskRepository,
        private readonly PersonRepository $personRepository,
    ) {}

    /**
     * Generiert Vorschläge für alle leeren Slots des Monats.
     *
     * @return array{proposals: array, warnings: array}
     */
    public function generateProposals(Assembly $assembly, int $year, int $month, array $grid, array $tasks): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to   = $from->modify('last day of this month');

        $absentByPersonDate  = $this->buildAbsenceMap($assembly->getId(), $from, $to);
        $externalByDayPerson = $this->buildExternalTaskMap(array_keys($grid));
        $fairness            = $this->buildFairnessMap($assembly->getId(), $year);
        $personsByTask       = $this->buildPersonsByTask($assembly->getId(), $tasks);

        $proposals = [];
        $warnings  = [];

        foreach ($grid as $dayId => $row) {
            if ($row['isBlocked']) {
                continue;
            }

            $day              = $row['day'];
            $dateStr          = $day->getDate()->format('Y-m-d');
            $existingByTask   = $row['assignments'];

            $takenThisDay = [];
            foreach ($existingByTask as $assignment) {
                $takenThisDay[$assignment->getPerson()->getId()] = true;
            }

            foreach ($tasks as $task) {
                $taskId = $task->getId();

                if (isset($existingByTask[$taskId])) {
                    continue;
                }

                $candidates = $personsByTask[$taskId] ?? [];
                $available  = $this->filterCandidates(
                    $candidates,
                    $dayId,
                    $dateStr,
                    $absentByPersonDate,
                    $externalByDayPerson,
                    $takenThisDay
                );

                if (empty($available)) {
                    $warnings[] = [
                        'dayDate'  => $day->getDate()->format('d.m.Y'),
                        'taskName' => $task->getName(),
                    ];
                    continue;
                }

                $chosen = $this->pickBestCandidate($available, $taskId, $fairness);

                $takenThisDay[$chosen->getId()] = true;

                $proposals[] = [
                    'dayId'      => $dayId,
                    'taskId'     => $taskId,
                    'personId'   => $chosen->getId(),
                    'personName' => $chosen->getName(),
                    'taskName'   => $task->getName(),
                    'deptName'   => $task->getDepartment()->getName(),
                    'dayDate'    => $day->getDate()->format('d.m.Y'),
                ];
            }
        }

        return ['proposals' => $proposals, 'warnings' => $warnings];
    }

    /** Abwesenheiten als [personId][dateStr] Map. */
    private function buildAbsenceMap(int $assemblyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $absences = $this->absenceRepository->findByAssemblyAndPeriod($assemblyId, $from, $to);
        $map = [];
        foreach ($absences as $absence) {
            $pid = $absence->getPerson()->getId();
            $d   = $absence->getStartDate();
            while ($d <= $absence->getEndDate()) {
                $map[$pid][$d->format('Y-m-d')] = true;
                $d = $d->modify('+1 day');
            }
        }
        return $map;
    }

    /** Externe Aufgaben als [dayId][personId] Map. */
    private function buildExternalTaskMap(array $dayIds): array
    {
        $map = [];
        foreach ($this->externalTaskRepository->findByDays($dayIds) as $et) {
            $map[$et->getDay()->getId()][$et->getPerson()->getId()] = true;
        }
        return $map;
    }

    /** Zuteilungsanzahl als [personId][taskId] Map für das Jahr (Fairness-Score). */
    private function buildFairnessMap(int $assemblyId, int $year): array
    {
        $map = [];
        foreach ($this->assignmentRepository->countByPersonAndTask($assemblyId, $year, null) as $row) {
            $map[(int) $row['personId']][(int) $row['taskId']] = (int) $row['cnt'];
        }
        return $map;
    }

    /** Qualifizierte Personen pro Aufgabe, Fallback auf alle Personen. */
    private function buildPersonsByTask(int $assemblyId, array $tasks): array
    {
        $allPersons    = null;
        $personsByTask = [];
        foreach ($tasks as $task) {
            $eligible = $this->personRepository->findByAssemblyAndTask($assemblyId, $task->getId());
            if (empty($eligible)) {
                $allPersons ??= $this->personRepository->findByAssembly($assemblyId);
                $eligible = $allPersons;
            }
            $personsByTask[$task->getId()] = $eligible;
        }
        return $personsByTask;
    }

    /** Filtert Kandidaten: Abwesende, extern eingeplante und bereits zugeteilte Personen werden entfernt. */
    private function filterCandidates(
        array $candidates,
        int $dayId,
        string $dateStr,
        array $absentByPersonDate,
        array $externalByDayPerson,
        array $takenThisDay
    ): array {
        return array_values(array_filter($candidates, function ($person) use (
            $dayId, $dateStr, $absentByPersonDate, $externalByDayPerson, $takenThisDay
        ) {
            $pid = $person->getId();
            if (isset($absentByPersonDate[$pid][$dateStr])) {
                return false;
            }
            if (isset($externalByDayPerson[$dayId][$pid])) {
                return false;
            }
            if (isset($takenThisDay[$pid])) {
                return false;
            }
            return true;
        }));
    }

    /** Wählt die Person mit dem niedrigsten Fairness-Score; bei Gleichstand zufällig. */
    private function pickBestCandidate(array $candidates, int $taskId, array $fairness): object
    {
        $minScore = PHP_INT_MAX;
        foreach ($candidates as $person) {
            $score = $fairness[$person->getId()][$taskId] ?? 0;
            if ($score < $minScore) {
                $minScore = $score;
            }
        }

        $best = array_values(array_filter($candidates, function ($person) use ($taskId, $fairness, $minScore) {
            return ($fairness[$person->getId()][$taskId] ?? 0) === $minScore;
        }));

        return $best[array_rand($best)];
    }
}
