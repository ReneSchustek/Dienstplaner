<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\TaskRepository;
use App\Service\AssemblyContext;
use App\Service\ExportService;
use App\Service\PlanerScope;
use App\Service\PlanningService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

#[Route('/export')]
#[IsGranted('ROLE_PLANER')]
class ExportController extends AbstractController
{
    public function __construct(
        private readonly ExportService $exportService,
        private readonly PlanningService $planningService,
        private readonly AssemblyContext $assemblyContext,
        private readonly TaskRepository $taskRepository,
        private readonly TranslatorInterface $translator,
        private readonly PlanerScope $planerScope,
    ) {}

    #[Route('', name: 'export_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->redirectToRoute('dashboard');
        }

        $year = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        return $this->render('export/index.html.twig', [
            'year' => $year,
            'month' => $month,
            'assembly' => $assembly,
        ]);
    }

    #[Route('/pdf', name: 'export_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->redirectToRoute('dashboard');
        }

        $year = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $grid = $this->planningService->getPlanningGridForMonth($assembly, $year, $month);
        $tasks = $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds);
        $html = $this->renderView('export/pdf_monthly.html.twig', [
            'grid'      => $grid,
            'tasks'     => $tasks,
            'assembly'  => $assembly,
            'year'      => $year,
            'month'     => $month,
            'monthName' => $this->translator->trans('month.' . $month) . ' ' . $year,
        ]);

        $pdf = $this->exportService->exportMonthlyPlanPdf($assembly, $year, $month, $html);
        $filename = sprintf('dienstplan-%s-%d-%02d.pdf', $assembly->getName(), $year, $month);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    #[Route('/excel', name: 'export_excel', methods: ['GET'])]
    public function excel(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return new StreamedResponse(fn() => null, 302);
        }

        $year = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $grid  = $this->planningService->getPlanningGridForMonth($assembly, $year, $month);
        $tasks = $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds);

        $tempFile = $this->exportService->exportExcel($assembly, $year, $month, $grid, $tasks);
        $filename = sprintf('planung-%s-%d-%02d.xlsx', $assembly->getName(), $year, $month);

        return new StreamedResponse(function () use ($tempFile) {
            readfile($tempFile);
            unlink($tempFile);
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    #[Route('/word', name: 'export_word', methods: ['GET'])]
    public function word(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return new StreamedResponse(fn() => null, 302);
        }

        $year = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $grid      = $this->planningService->getPlanningGridForMonth($assembly, $year, $month);
        $tasks     = $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds);
        $monthName = $this->translator->trans('month.' . $month) . ' ' . $year;

        $tempFile = $this->exportService->generateWord($assembly, $year, $month, $grid, $tasks, $monthName);
        $filename = sprintf('Dienstplan_%s_%d-%02d.docx', $assembly->getName(), $year, $month);

        return new StreamedResponse(function () use ($tempFile) {
            readfile($tempFile);
            unlink($tempFile);
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
