<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AssemblyRepository;
use App\Repository\PersonRepository;
use App\Repository\TaskRepository;
use App\Service\AssemblyContext;
use App\Service\PlanningService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly AssemblyRepository $assemblyRepository,
        private readonly PersonRepository $personRepository,
        private readonly TaskRepository $taskRepository,
        private readonly PlanningService $planningService,
        private readonly AssemblyContext $assemblyContext,
    ) {}

    #[Route('/', name: 'dashboard')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $assemblies = $this->isGranted('ROLE_ADMIN')
            ? $this->assemblyRepository->findAll()
            : ($assembly ? [$assembly] : []);

        $persons = $assembly
            ? $this->personRepository->findByAssembly($assembly->getId())
            : [];

        $year  = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        if ($month < 1) { $month = 12; $year--; }
        if ($month > 12) { $month = 1; $year++; }

        $grid = [];
        $tasks = [];

        if ($assembly !== null) {
            $tasks = $this->taskRepository->findByAssembly($assembly->getId());
            $grid = $this->planningService->getPlanningGridForMonth($assembly, $year, $month);
        }

        $prevMonth = $month - 1;
        $prevYear  = $year;
        if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

        $nextMonth = $month + 1;
        $nextYear  = $year;
        if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

        $departmentBlocks = $this->planningService->buildDepartmentBlocks($tasks);

        return $this->render('dashboard/index.html.twig', [
            'assemblies'       => $assemblies,
            'persons'          => $persons,
            'grid'             => $grid,
            'tasks'            => $tasks,
            'departmentBlocks' => $departmentBlocks,
            'year'             => $year,
            'month'            => $month,
            'prevYear'         => $prevYear,
            'prevMonth'        => $prevMonth,
            'nextYear'         => $nextYear,
            'nextMonth'        => $nextMonth,
            'assembly'         => $assembly,
        ]);
    }
}
