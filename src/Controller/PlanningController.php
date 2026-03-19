<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AssignmentRepository;
use App\Repository\DayRepository;
use App\Repository\PersonRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Repository\DepartmentRepository;
use App\Service\AssemblyContext;
use App\Service\AssignmentService;
use App\Service\PlanerScope;
use App\Service\IcsGeneratorService;
use App\Service\PlanningLockService;
use App\Service\PlanningProposalService;
use App\Service\PlanningService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/planning')]
#[IsGranted('ROLE_USER')]
class PlanningController extends AbstractController
{
    public function __construct(
        private readonly PlanningService $planningService,
        private readonly PlanningProposalService $proposalService,
        private readonly AssignmentService $assignmentService,
        private readonly PlanningLockService $lockService,
        private readonly PersonRepository $personRepository,
        private readonly TaskRepository $taskRepository,
        private readonly DayRepository $dayRepository,
        private readonly DepartmentRepository $departmentRepository,
        private readonly AssemblyContext $assemblyContext,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly IcsGeneratorService $icsGenerator,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly PlanerScope $planerScope,
    ) {}

    #[Route('', name: 'planning_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            $this->addFlash('warning', 'planning.no_assembly');
            return $this->redirectToRoute('dashboard');
        }

        $year = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        $grid = $this->planningService->getPlanningGridForMonth($assembly, $year, $month);

        $allTasks = $this->taskRepository->findByAssembly($assembly->getId());
        $tasks = $this->filterTasksForUser($user, $allTasks);
        $personsByTask = $this->buildPersonsByTask($assembly->getId(), $tasks);
        $departmentBlocks = $this->planningService->buildDepartmentBlocks($tasks);

        return $this->render('planning/index.html.twig', [
            'grid' => $grid,
            'tasks' => $tasks,
            'departmentBlocks' => $departmentBlocks,
            'personsByTask' => $personsByTask,
            'assembly' => $assembly,
            'year' => $year,
            'month' => $month,
        ]);
    }

    #[Route('/lock/{departmentId}', name: 'planning_lock_acquire', methods: ['POST'])]
    #[IsGranted('ROLE_PLANER')]
    public function acquireLock(int $departmentId): Response
    {
        $department = $this->departmentRepository->find($departmentId);
        if ($department === null) {
            return $this->json(['error' => 'Department not found'], 404);
        }

        /** @var User $user */
        $user   = $this->getUser();
        $result = $this->lockService->acquire($department, $user);

        return $this->json($result);
    }

    #[Route('/lock/{departmentId}/release', name: 'planning_lock_release', methods: ['POST'])]
    #[IsGranted('ROLE_PLANER')]
    public function releaseLock(int $departmentId): Response
    {
        $department = $this->departmentRepository->find($departmentId);
        if ($department === null) {
            return $this->json(['error' => 'Department not found'], 404);
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->lockService->release($department, $user);

        return $this->json(['released' => true]);
    }

    #[Route('/lock-release-all', name: 'planning_lock_release_all', methods: ['POST'])]
    #[IsGranted('ROLE_PLANER')]
    public function releaseAllLocks(): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly !== null) {
            $this->lockService->releaseAllByUser($user, $assembly);
        }

        return $this->json(['released' => true]);
    }

    #[Route('/lock-status', name: 'planning_lock_status', methods: ['GET'])]
    #[IsGranted('ROLE_PLANER')]
    public function lockStatus(): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->json([]);
        }

        $status = $this->lockService->getStatusForAssembly($assembly);

        return $this->json($status);
    }

    private function filterTasksForUser(User $user, array $allTasks): array
    {
        if (!$this->planerScope->isActive($user)) {
            return $allTasks;
        }

        $departmentIds = $this->planerScope->getDepartmentIds($user);
        if (empty($departmentIds)) {
            return [];
        }

        return array_values(array_filter(
            $allTasks,
            fn($t) => in_array($t->getDepartment()->getId(), $departmentIds, true)
        ));
    }

    private function buildPersonsByTask(int $assemblyId, array $tasks): array
    {
        $allPersons = null;
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

    #[Route('/notify/{year}/{month}', name: 'planning_notify', methods: ['POST'])]
    #[IsGranted('ROLE_PLANER')]
    public function notify(Request $request, int $year, int $month): Response
    {
        if (!$this->isCsrfTokenValid('planning-notify-' . $year . '-' . $month, $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('planning_index', ['year' => $year, 'month' => $month]);
        }

        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            $this->addFlash('warning', 'planning.no_assembly');
            return $this->redirectToRoute('dashboard');
        }

        $grouped      = $this->assignmentRepository->findByAssemblyAndMonthGroupedByPerson($assembly->getId(), $year, $month);
        $usersByPerson = $this->userRepository->findByAssemblyIndexedByPerson($assembly->getId());

        $monthName    = $this->translator->trans('month.' . $month);
        $assemblyName = $assembly->getName();
        $subject      = sprintf('[Dienstplan] %s %d – %s', $monthName, $year, $assemblyName);
        $senderEmail  = $_ENV['MAILER_SENDER_EMAIL'] ?? 'noreply@dienstplaner.local';
        $senderName   = $_ENV['MAILER_SENDER_NAME'] ?? 'Dienstplaner';

        $sent    = 0;
        $skipped = [];

        foreach ($grouped as $personId => $assignments) {
            $person = $assignments[0]->getPerson();

            if (!isset($usersByPerson[$personId])) {
                $skipped[] = $person->getName();
                continue;
            }

            $recipient = $usersByPerson[$personId];
            $email     = $recipient->getEmail();

            $ics  = $this->icsGenerator->generateForAssignments($assignments, $assemblyName);
            $body = $this->renderView('email/monthly_plan_notification.html.twig', [
                'person'       => $person,
                'assignments'  => $assignments,
                'monthName'    => $monthName,
                'year'         => $year,
                'assemblyName' => $assemblyName,
            ]);

            try {
                $message = (new Email())
                    ->from(new Address($senderEmail, $senderName))
                    ->to($email)
                    ->subject($subject)
                    ->html($body)
                    ->attach($ics, 'dienstplan.ics', 'text/calendar');

                $this->mailer->send($message);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error('Monthly notification failed', [
                    'recipient' => $email,
                    'error'     => $e->getMessage(),
                ]);
                $skipped[] = $person->getName();
            }
        }

        $this->addFlash('success', sprintf('%s: %d', $this->translator->trans('planning.notify_sent'), $sent));

        if (!empty($skipped)) {
            $this->addFlash('warning', $this->translator->trans('planning.notify_skipped') . ': ' . implode(', ', $skipped));
        }

        return $this->redirectToRoute('planning_index', ['year' => $year, 'month' => $month]);
    }

    #[Route('/suggest/{year}/{month}', name: 'planning_suggest', methods: ['POST'])]
    #[IsGranted('ROLE_PLANER')]
    public function suggest(int $year, int $month): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->json(['error' => 'No assembly'], 400);
        }

        $grid     = $this->planningService->getPlanningGridForMonth($assembly, $year, $month);
        $allTasks = $this->taskRepository->findByAssembly($assembly->getId());
        $tasks    = $this->filterTasksForUser($user, $allTasks);

        $result = $this->proposalService->generateProposals($assembly, $year, $month, $grid, $tasks);

        return $this->json($result);
    }

    #[Route('/assign', name: 'planning_assign', methods: ['POST'])]
    #[IsGranted('ROLE_PLANER')]
    public function assign(Request $request): Response
    {
        $dayId    = (int)  $request->request->get('day_id');
        $taskId   = (int)  $request->request->get('task_id');
        $personId = (int)  $request->request->get('person_id');
        $force    = (bool) $request->request->get('force', false);

        $day    = $this->dayRepository->find($dayId);
        $task   = $this->taskRepository->find($taskId);
        $person = $personId > 0 ? $this->personRepository->find($personId) : null;

        if ($day === null || $task === null) {
            return $this->json(['error' => 'Ungültige Daten'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($this->planerScope->isActive($user)) {
            $ids = $this->planerScope->getDepartmentIds($user);
            if (!in_array($task->getDepartment()->getId(), $ids, true)) {
                return $this->json(['error' => 'Kein Zugriff auf diese Aufgabe'], 403);
            }
        }

        if ($this->lockService->isLockedByOther($task->getDepartment(), $user)) {
            return $this->json(['error' => 'Abteilung wird von einem anderen Planer bearbeitet.'], 423);
        }

        try {
            $existing = $this->assignmentService->findByTaskAndDay($task, $day);
            if ($existing !== null) {
                $this->assignmentService->removeAssignment($existing);
            }

            if ($person !== null) {
                $this->assignmentService->assign($person, $task, $day, $force);
            }

            return $this->json(['success' => true]);
        } catch (\DomainException $e) {
            return $this->json([
                'error'    => $e->getMessage(),
                'canForce' => $e->getCode() === 1,
            ], 422);
        }
    }
}
