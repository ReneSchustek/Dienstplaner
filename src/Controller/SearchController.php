<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AbsenceRepository;
use App\Repository\AssignmentRepository;
use App\Repository\DepartmentRepository;
use App\Repository\PersonRepository;
use App\Repository\TaskRepository;
use App\Service\AssemblyContext;
use App\Service\PlanerScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/search')]
#[IsGranted('ROLE_PLANER')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly AssemblyContext $assemblyContext,
        private readonly PersonRepository $personRepository,
        private readonly TaskRepository $taskRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly AbsenceRepository $absenceRepository,
        private readonly DepartmentRepository $departmentRepository,
        private readonly PlanerScope $planerScope,
    ) {}

    #[Route('', name: 'search_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('search_persons');
    }

    #[Route('/persons', name: 'search_persons', methods: ['GET'])]
    public function persons(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->render('search/persons.html.twig', ['query' => '', 'persons' => []]);
        }

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $query = trim($request->query->getString('q'));
        $persons = [];
        if ($query !== '') {
            $persons = $this->personRepository->searchByName($assembly->getId(), $query, $departmentIds);
        }

        return $this->render('search/persons.html.twig', [
            'query' => $query,
            'persons' => $persons,
        ]);
    }

    #[Route('/persons/{id}', name: 'search_person_detail', methods: ['GET'])]
    public function personDetail(int $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $person = $this->personRepository->find($id);
        if ($person === null || $assembly === null || $person->getAssembly()->getId() !== $assembly->getId()) {
            throw $this->createNotFoundException();
        }

        if ($this->planerScope->isActive($user)) {
            $ids = $this->planerScope->getDepartmentIds($user);
            $personDeptIds = $person->getTasks()->map(fn($t) => $t->getDepartment()->getId())->toArray();
            if (empty(array_intersect($ids, $personDeptIds))) {
                throw $this->createAccessDeniedException();
            }
        }

        $from = null;
        $to = null;
        $fromStr = $request->query->getString('from');
        $toStr = $request->query->getString('to');
        if ($fromStr !== '') {
            $from = new \DateTimeImmutable($fromStr);
        }
        if ($toStr !== '') {
            $to = new \DateTimeImmutable($toStr);
        }

        $assignments = $this->assignmentRepository->findByPersonAndPeriod($person->getId(), $from, $to);
        $absences = $this->absenceRepository->findByPerson($person->getId());

        $countByTask = [];
        foreach ($assignments as $assignment) {
            $taskId = $assignment->getTask()->getId();
            $countByTask[$taskId] = ($countByTask[$taskId] ?? 0) + 1;
        }

        return $this->render('search/person_detail.html.twig', [
            'person' => $person,
            'assignments' => $assignments,
            'absences' => $absences,
            'countByTask' => $countByTask,
            'from' => $from,
            'to' => $to,
            'fromStr' => $fromStr,
            'toStr' => $toStr,
        ]);
    }

    #[Route('/tasks', name: 'search_tasks', methods: ['GET'])]
    public function tasks(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->render('search/tasks.html.twig', [
                'query' => '', 'deptId' => null, 'departments' => [], 'tasks' => [],
            ]);
        }

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $query = trim($request->query->getString('q'));
        $deptId = (int) ($request->query->get('dept') ?: 0) ?: null;

        $departments = $departmentIds !== null
            ? (empty($departmentIds) ? [] : $this->departmentRepository->findBy(['id' => $departmentIds], ['name' => 'ASC']))
            : $this->departmentRepository->findByAssembly($assembly->getId());

        $allTasks = $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds);
        $tasks = array_filter($allTasks, function ($task) use ($query, $deptId) {
            if ($query !== '' && stripos($task->getName(), $query) === false) {
                return false;
            }
            if ($deptId !== null && $task->getDepartment()->getId() !== $deptId) {
                return false;
            }
            return true;
        });

        return $this->render('search/tasks.html.twig', [
            'query' => $query,
            'deptId' => $deptId,
            'departments' => $departments,
            'tasks' => array_values($tasks),
        ]);
    }

    #[Route('/tasks/{id}', name: 'search_task_detail', methods: ['GET'])]
    public function taskDetail(int $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $task = $this->taskRepository->find($id);
        if ($task === null || $assembly === null || $task->getDepartment()->getAssembly()->getId() !== $assembly->getId()) {
            throw $this->createNotFoundException();
        }

        if ($this->planerScope->isActive($user)) {
            $ids = $this->planerScope->getDepartmentIds($user);
            if (!in_array($task->getDepartment()->getId(), $ids, true)) {
                throw $this->createAccessDeniedException();
            }
        }

        $eligiblePersons = $this->personRepository->findByAssemblyAndTask($assembly->getId(), $task->getId());
        $allPersons = $this->personRepository->findByAssembly($assembly->getId());
        $recentAssignments = $this->assignmentRepository->findByTaskAndPeriod($task->getId(), null, null);

        $countByPerson = [];
        foreach ($this->assignmentRepository->countByTaskGroupedByPerson($task->getId()) as $row) {
            $countByPerson[(int) $row['personId']] = (int) $row['cnt'];
        }

        $lastAssignment = $recentAssignments[0] ?? null;

        return $this->render('search/task_detail.html.twig', [
            'task' => $task,
            'eligiblePersons' => $eligiblePersons,
            'allPersons' => $allPersons,
            'countByPerson' => $countByPerson,
            'lastAssignment' => $lastAssignment,
        ]);
    }

    #[Route('/availability', name: 'search_availability', methods: ['GET'])]
    public function availability(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->render('search/availability.html.twig', [
                'dateStr' => '', 'taskId' => null, 'tasks' => [], 'available' => [], 'date' => null,
            ]);
        }

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $dateStr = $request->query->getString('date');
        $taskId = (int) ($request->query->get('task') ?: 0) ?: null;
        $tasks = $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds);

        $available = [];
        $date = null;
        if ($dateStr !== '') {
            $date = new \DateTimeImmutable($dateStr);
            $available = $this->personRepository->findAvailableForDate($assembly->getId(), $date, $taskId, $departmentIds);
        }

        return $this->render('search/availability.html.twig', [
            'dateStr' => $dateStr,
            'taskId' => $taskId,
            'tasks' => $tasks,
            'available' => $available,
            'date' => $date,
        ]);
    }

    #[Route('/overview/task-persons', name: 'search_overview_task_persons', methods: ['GET'])]
    public function overviewTaskPersons(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->render('search/overview_task_persons.html.twig', [
                'data' => [], 'departments' => [], 'deptId' => null,
            ]);
        }

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $deptId = (int) ($request->query->get('dept') ?: 0) ?: null;

        $departments = $departmentIds !== null
            ? (empty($departmentIds) ? [] : $this->departmentRepository->findBy(['id' => $departmentIds], ['name' => 'ASC']))
            : $this->departmentRepository->findByAssembly($assembly->getId());

        $tasks = $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds);

        $data = [];
        foreach ($tasks as $task) {
            if ($deptId !== null && $task->getDepartment()->getId() !== $deptId) {
                continue;
            }
            $data[] = [
                'task' => $task,
                'persons' => $this->personRepository->findByAssemblyAndTask($assembly->getId(), $task->getId()),
            ];
        }

        if ($request->query->getString('format') === 'csv') {
            return $this->exportCsvTaskPersons($data);
        }

        return $this->render('search/overview_task_persons.html.twig', [
            'data' => $data,
            'departments' => $departments,
            'deptId' => $deptId,
        ]);
    }

    #[Route('/overview/person-tasks', name: 'search_overview_person_tasks', methods: ['GET'])]
    public function overviewPersonTasks(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->render('search/overview_person_tasks.html.twig', [
                'tasksByPerson' => [], 'departments' => [], 'deptId' => null,
            ]);
        }

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $deptId = (int) ($request->query->get('dept') ?: 0) ?: null;

        $departments = $departmentIds !== null
            ? (empty($departmentIds) ? [] : $this->departmentRepository->findBy(['id' => $departmentIds], ['name' => 'ASC']))
            : $this->departmentRepository->findByAssembly($assembly->getId());

        $persons = $this->personRepository->findByAssembly($assembly->getId(), $departmentIds);
        $allTasks = $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds);

        $pairMap = $this->personRepository->findPersonTaskPairs($assembly->getId(), $departmentIds);
        $tasksById = [];
        foreach ($allTasks as $task) {
            $tasksById[$task->getId()] = $task;
        }

        $tasksByPerson = [];
        foreach ($persons as $person) {
            $taskIds = $pairMap[$person->getId()] ?? [];
            $personTasks = [];
            foreach ($taskIds as $taskId) {
                if (!isset($tasksById[$taskId])) {
                    continue;
                }
                $task = $tasksById[$taskId];
                if ($deptId !== null && $task->getDepartment()->getId() !== $deptId) {
                    continue;
                }
                $personTasks[] = $task;
            }
            $tasksByPerson[] = ['person' => $person, 'tasks' => $personTasks];
        }

        if ($request->query->getString('format') === 'csv') {
            return $this->exportCsvPersonTasks($tasksByPerson);
        }

        return $this->render('search/overview_person_tasks.html.twig', [
            'tasksByPerson' => $tasksByPerson,
            'departments' => $departments,
            'deptId' => $deptId,
        ]);
    }

    #[Route('/overview/history', name: 'search_overview_history', methods: ['GET'])]
    public function overviewHistory(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $fromStr = $request->query->getString('from', date('Y-m-01'));
        $toStr = $request->query->getString('to', date('Y-m-t'));
        $personId = (int) ($request->query->get('person') ?: 0) ?: null;
        $taskId   = (int) ($request->query->get('task') ?: 0) ?: null;
        $deptId   = (int) ($request->query->get('dept') ?: 0) ?: null;

        if ($assembly === null) {
            return $this->render('search/overview_history.html.twig', [
                'assignments' => [], 'persons' => [], 'tasks' => [], 'departments' => [],
                'fromStr' => $fromStr, 'toStr' => $toStr,
                'personId' => null, 'taskId' => null, 'deptId' => null,
            ]);
        }

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $from = new \DateTimeImmutable($fromStr);
        $to = new \DateTimeImmutable($toStr);

        $assignments = $this->assignmentRepository->findByAssemblyAndPeriodFiltered(
            $assembly->getId(), $from, $to, $personId, $taskId, $deptId, $departmentIds
        );

        $persons = $this->personRepository->findByAssembly($assembly->getId(), $departmentIds);
        $tasks = $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds);
        $departments = $departmentIds !== null
            ? (empty($departmentIds) ? [] : $this->departmentRepository->findBy(['id' => $departmentIds], ['name' => 'ASC']))
            : $this->departmentRepository->findByAssembly($assembly->getId());

        if ($request->query->getString('format') === 'csv') {
            return $this->exportCsvHistory($assignments);
        }

        return $this->render('search/overview_history.html.twig', [
            'assignments' => $assignments,
            'persons' => $persons,
            'tasks' => $tasks,
            'departments' => $departments,
            'fromStr' => $fromStr,
            'toStr' => $toStr,
            'personId' => $personId,
            'taskId' => $taskId,
            'deptId' => $deptId,
        ]);
    }

    #[Route('/overview/absences', name: 'search_overview_absences', methods: ['GET'])]
    public function overviewAbsences(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $fromStr = $request->query->getString('from', date('Y-m-01'));
        $toStr = $request->query->getString('to', date('Y-m-t'));

        if ($assembly === null) {
            return $this->render('search/overview_absences.html.twig', [
                'absences' => [], 'fromStr' => $fromStr, 'toStr' => $toStr,
            ]);
        }

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $from = new \DateTimeImmutable($fromStr);
        $to = new \DateTimeImmutable($toStr);

        $absences = $this->absenceRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to, $departmentIds);

        if ($request->query->getString('format') === 'csv') {
            return $this->exportCsvAbsences($absences);
        }

        return $this->render('search/overview_absences.html.twig', [
            'absences' => $absences,
            'fromStr' => $fromStr,
            'toStr' => $toStr,
        ]);
    }

    #[Route('/overview/task-stats', name: 'search_overview_task_stats', methods: ['GET'])]
    public function overviewTaskStats(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $fromStr = $request->query->getString('from', date('Y-m-01'));
        $toStr = $request->query->getString('to', date('Y-m-t'));

        if ($assembly === null) {
            return $this->render('search/overview_task_stats.html.twig', [
                'rows' => [], 'departments' => [], 'persons' => [],
                'fromStr' => $fromStr, 'toStr' => $toStr, 'totals' => [],
            ]);
        }

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $from = new \DateTimeImmutable($fromStr);
        $to = new \DateTimeImmutable($toStr);

        $rawCounts = $this->assignmentRepository->countByPersonAndDepartment($assembly->getId(), $from, $to, $departmentIds);
        $departments = $departmentIds !== null
            ? (empty($departmentIds) ? [] : $this->departmentRepository->findBy(['id' => $departmentIds], ['name' => 'ASC']))
            : $this->departmentRepository->findByAssembly($assembly->getId());
        $persons = $this->personRepository->findByAssembly($assembly->getId(), $departmentIds);

        // Aufbau: [personId => [deptId => count]]
        $countMap = [];
        foreach ($rawCounts as $row) {
            $pid = (int) $row['personId'];
            $did = (int) $row['deptId'];
            $cnt = (int) $row['cnt'];
            $countMap[$pid][$did] = $cnt;
        }

        // Zeilen mit Gesamtsumme aufbauen
        $rows = [];
        foreach ($persons as $person) {
            $pid = $person->getId();
            $deptCounts = $countMap[$pid] ?? [];
            $total = array_sum($deptCounts);
            if ($total === 0) {
                continue;
            }
            $rows[] = ['person' => $person, 'deptCounts' => $deptCounts, 'total' => $total];
        }

        usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);

        // Gesamtsumme pro Abteilung
        $totals = [];
        foreach ($departments as $dept) {
            $totals[$dept->getId()] = array_sum(array_column(
                array_map(fn($r) => ['v' => $r['deptCounts'][$dept->getId()] ?? 0], $rows),
                'v'
            ));
        }

        if ($request->query->getString('format') === 'csv') {
            return $this->exportCsvTaskStats($rows, $departments);
        }

        return $this->render('search/overview_task_stats.html.twig', [
            'rows' => $rows,
            'departments' => $departments,
            'persons' => $persons,
            'fromStr' => $fromStr,
            'toStr' => $toStr,
            'totals' => $totals,
        ]);
    }

    /** Erstellt eine StreamedResponse mit UTF-8-CSV-Inhalt (inkl. BOM). */
    private function streamCsv(string $filename, callable $write): StreamedResponse
    {
        return new StreamedResponse(function () use ($write) {
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF");
            $write($out);
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** Exportiert die Aufgabenstatistik (Personen × Abteilungen) als UTF-8-CSV. */
    private function exportCsvTaskStats(array $rows, array $departments): StreamedResponse
    {
        return $this->streamCsv('aufgabenstatistik.csv', function ($out) use ($rows, $departments) {
            $header = ['Person'];
            foreach ($departments as $dept) {
                $header[] = $dept->getName();
            }
            $header[] = 'Gesamt';
            fputcsv($out, $header, ';');
            foreach ($rows as $row) {
                $line = [$row['person']->getName()];
                foreach ($departments as $dept) {
                    $line[] = $row['deptCounts'][$dept->getId()] ?? 0;
                }
                $line[] = $row['total'];
                fputcsv($out, $line, ';');
            }
        });
    }

    /** Exportiert die Aufgabe-Personen-Zuordnung als UTF-8-CSV. */
    private function exportCsvTaskPersons(array $data): StreamedResponse
    {
        return $this->streamCsv('aufgaben-personen.csv', function ($out) use ($data) {
            fputcsv($out, ['Abteilung', 'Aufgabe', 'Person'], ';');
            foreach ($data as $row) {
                $task = $row['task'];
                foreach ($row['persons'] as $person) {
                    fputcsv($out, [$task->getDepartment()->getName(), $task->getName(), $person->getName()], ';');
                }
                if (empty($row['persons'])) {
                    fputcsv($out, [$task->getDepartment()->getName(), $task->getName(), ''], ';');
                }
            }
        });
    }

    /** Exportiert die Personen-Aufgaben-Zuordnung als UTF-8-CSV. */
    private function exportCsvPersonTasks(array $data): StreamedResponse
    {
        return $this->streamCsv('personen-aufgaben.csv', function ($out) use ($data) {
            fputcsv($out, ['Person', 'Abteilung', 'Aufgabe'], ';');
            foreach ($data as $row) {
                $person = $row['person'];
                foreach ($row['tasks'] as $task) {
                    fputcsv($out, [$person->getName(), $task->getDepartment()->getName(), $task->getName()], ';');
                }
                if (empty($row['tasks'])) {
                    fputcsv($out, [$person->getName(), '', ''], ';');
                }
            }
        });
    }

    /** Exportiert die Planungshistorie (Datum, Person, Abteilung, Aufgabe) als UTF-8-CSV. */
    private function exportCsvHistory(array $assignments): StreamedResponse
    {
        return $this->streamCsv('planungshistorie.csv', function ($out) use ($assignments) {
            fputcsv($out, ['Datum', 'Person', 'Abteilung', 'Aufgabe'], ';');
            foreach ($assignments as $a) {
                fputcsv($out, [
                    $a->getDay()->getDate()->format('d.m.Y'),
                    $a->getPerson()->getName(),
                    $a->getTask()->getDepartment()->getName(),
                    $a->getTask()->getName(),
                ], ';');
            }
        });
    }

    /** Exportiert Abwesenheiten (Person, Von, Bis, Anmerkung) als UTF-8-CSV. */
    private function exportCsvAbsences(array $absences): StreamedResponse
    {
        return $this->streamCsv('abwesenheiten.csv', function ($out) use ($absences) {
            fputcsv($out, ['Person', 'Von', 'Bis', 'Anmerkung'], ';');
            foreach ($absences as $a) {
                fputcsv($out, [
                    $a->getPerson()->getName(),
                    $a->getStartDate()->format('d.m.Y'),
                    $a->getEndDate()->format('d.m.Y'),
                    $a->getNote() ?? '',
                ], ';');
            }
        });
    }

    #[Route('/fairness', name: 'search_fairness', methods: ['GET'])]
    public function fairness(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $year  = $request->query->getInt('year', (int) date('Y'));
        $month = $request->query->getInt('month', 0) ?: null;

        if ($assembly === null) {
            return $this->render('search/fairness.html.twig', [
                'rows' => [], 'tasks' => [], 'avgs' => [], 'year' => $year, 'month' => $month,
            ]);
        }

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $persons = $this->personRepository->findByAssembly($assembly->getId(), $departmentIds);
        $tasks   = $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds);

        $rawCounts = $this->assignmentRepository->countByPersonAndTask($assembly->getId(), $year, $month, $departmentIds);

        // matrix[personId][taskId] = count
        $matrix = [];
        foreach ($rawCounts as $row) {
            $matrix[(int) $row['personId']][(int) $row['taskId']] = (int) $row['cnt'];
        }

        // per-task totals and averages across all persons
        $taskTotals = [];
        foreach ($tasks as $task) {
            $tid = $task->getId();
            $taskTotals[$tid] = 0;
            foreach ($persons as $person) {
                $taskTotals[$tid] += $matrix[$person->getId()][$tid] ?? 0;
            }
        }

        $personCount = count($persons);
        $avgs = [];
        foreach ($tasks as $task) {
            $avgs[$task->getId()] = $personCount > 0 ? $taskTotals[$task->getId()] / $personCount : 0;
        }

        // build rows
        $rows = [];
        foreach ($persons as $person) {
            $pid   = $person->getId();
            $total = 0;
            $cells = [];
            foreach ($tasks as $task) {
                $tid   = $task->getId();
                $count = $matrix[$pid][$tid] ?? 0;
                $total += $count;
                $avg   = $avgs[$tid];
                $class = 'neutral';
                if ($avg > 0) {
                    if ($count > $avg * 1.2) {
                        $class = 'over';
                    } elseif ($count < $avg * 0.8) {
                        $class = 'under';
                    }
                }
                $cells[$tid] = ['count' => $count, 'class' => $class];
            }
            $rows[] = ['person' => $person, 'cells' => $cells, 'total' => $total];
        }

        usort($rows, fn ($a, $b) => strcmp($a['person']->getName(), $b['person']->getName()));

        if ($request->query->getString('format') === 'csv') {
            return $this->exportCsvFairness($rows, $tasks, $avgs);
        }

        return $this->render('search/fairness.html.twig', [
            'rows'  => $rows,
            'tasks' => $tasks,
            'avgs'  => $avgs,
            'year'  => $year,
            'month' => $month,
        ]);
    }

    /** Exportiert die Fairness-Rotations-Tabelle inkl. Durchschnittzeile als UTF-8-CSV. */
    private function exportCsvFairness(array $rows, array $tasks, array $avgs): StreamedResponse
    {
        return $this->streamCsv('fairness-rotation.csv', function ($out) use ($rows, $tasks, $avgs) {
            $header = ['Person'];
            foreach ($tasks as $task) {
                $header[] = $task->getName();
            }
            $header[] = 'Gesamt';
            fputcsv($out, $header, ';');

            foreach ($rows as $row) {
                $line = [$row['person']->getName()];
                foreach ($tasks as $task) {
                    $line[] = $row['cells'][$task->getId()]['count'];
                }
                $line[] = $row['total'];
                fputcsv($out, $line, ';');
            }

            $avgLine = ['Ø Durchschnitt'];
            foreach ($tasks as $task) {
                $avgLine[] = number_format($avgs[$task->getId()], 1, ',', '');
            }
            $avgLine[] = '';
            fputcsv($out, $avgLine, ';');
        });
    }
}
