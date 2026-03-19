<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assembly;
use App\Repository\AssignmentRepository;
use App\Repository\DayRepository;
use App\Repository\ExternalTaskRepository;
use App\Repository\AbsenceRepository;

/**
 * Lädt und strukturiert die Planungsdaten für einen Monat.
 *
 * Kombiniert Planungstage, Zuteilungen, Abwesenheiten und externe Aufgaben
 * zu einer zusammenhängenden Planungsansicht.
 */
class PlanningService
{
    public function __construct(
        private readonly DayRepository $dayRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly AbsenceRepository $absenceRepository,
        private readonly ExternalTaskRepository $externalTaskRepository,
        private readonly DayGeneratorService $dayGeneratorService,
        private readonly PlanningRuleService $planningRuleService,
    ) {}

    public function getPlanningGridForMonth(Assembly $assembly, int $year, int $month): array
    {
        $rawDays = $this->dayGeneratorService->generateDaysForMonth($assembly, $year, $month);

        $dayMeta = $this->planningRuleService->applyRules($assembly, $rawDays, $year, $month);

        $dayIds = array_keys($dayMeta);
        $assignments = $this->assignmentRepository->findByDays($dayIds);
        $externalTasks = $this->buildExternalTaskMap($dayIds);

        $grid = [];
        foreach ($dayMeta as $dayId => $meta) {
            $grid[$dayId] = [
                'day' => $meta['day'],
                'assignments' => [],
                'externalTasks' => $externalTasks[$dayId] ?? [],
                'specialLabel' => $meta['specialLabel'],
                'isBlocked' => $meta['isBlocked'],
            ];
        }

        foreach ($assignments as $assignment) {
            $dayId = $assignment->getDay()->getId();
            if (isset($grid[$dayId])) {
                $grid[$dayId]['assignments'][$assignment->getTask()->getId()] = $assignment;
            }
        }

        return $grid;
    }

    /** Gruppiert Aufgaben nach Abteilung, alphabetisch sortiert. */
    public function buildDepartmentBlocks(array $tasks): array
    {
        $blocks = [];
        foreach ($tasks as $task) {
            $dept   = $task->getDepartment();
            $deptId = $dept->getId();
            if (!isset($blocks[$deptId])) {
                $blocks[$deptId] = ['dept' => $dept, 'tasks' => []];
            }
            $blocks[$deptId]['tasks'][] = $task;
        }
        usort($blocks, fn ($a, $b) => strcmp($a['dept']->getName(), $b['dept']->getName()));
        return array_values($blocks);
    }

    private function buildExternalTaskMap(array $dayIds): array
    {
        $allExternalTasks = $this->externalTaskRepository->findByDays($dayIds);
        $map = array_fill_keys($dayIds, []);
        foreach ($allExternalTasks as $externalTask) {
            $map[$externalTask->getDay()->getId()][] = $externalTask;
        }
        return $map;
    }
}
