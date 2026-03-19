<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Day;
use App\Entity\ExternalTask;
use App\Entity\User;
use App\Form\ExternalTaskType;
use App\Repository\DayRepository;
use App\Repository\ExternalTaskRepository;
use App\Repository\PersonRepository;
use App\Service\AssemblyContext;
use App\Service\AssemblyService;
use App\Service\ExternalTaskService;
use App\Service\PdfImportService;
use App\Service\PlanerScope;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/external-tasks')]
#[IsGranted('ROLE_PLANER')]
class ExternalTaskController extends AbstractController
{
    public function __construct(
        private readonly ExternalTaskRepository $externalTaskRepository,
        private readonly PersonRepository $personRepository,
        private readonly DayRepository $dayRepository,
        private readonly ExternalTaskService $externalTaskService,
        private readonly AssemblyContext $assemblyContext,
        private readonly AssemblyService $assemblyService,
        private readonly PdfImportService $pdfImporter,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanerScope $planerScope,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'external_task_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $q    = trim($request->query->getString('q'));
        $page = max(1, $request->query->getInt('page', 1));
        $sort = $request->query->getString('sort', 'date');
        $dir  = $request->query->getString('dir', 'DESC');

        $now = new \DateTimeImmutable();
        $hasFilter = $request->query->has('year');
        $selectedMonth = $hasFilter ? $request->query->getInt('month', 0) : (int) $now->format('n');
        $selectedYear  = $hasFilter ? $request->query->getInt('year', 0)  : (int) $now->format('Y');

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $limit  = $assembly?->getPageSize() ?? 10;
        $result = $assembly
            ? $this->externalTaskRepository->findFiltered($assembly->getId(), $q, $page, $limit, $sort, $dir, $selectedMonth, $selectedYear, $departmentIds)
            : ['items' => [], 'total' => 0, 'pages' => 1];

        // Import-Review: falls Session-Daten vorhanden, Modal öffnen
        $pendingImport = $request->getSession()->get('external_task_import_pending');
        $importPersons = ($pendingImport !== null && $assembly !== null)
            ? $this->personRepository->findByAssembly($assembly->getId())
            : [];

        return $this->render('external_task/index.html.twig', [
            'externalTasks' => $result['items'],
            'total'         => $result['total'],
            'pages'         => $result['pages'],
            'page'          => $page,
            'q'             => $q,
            'sort'          => $sort,
            'dir'           => $dir,
            'selectedMonth' => $selectedMonth,
            'selectedYear'  => $selectedYear,
            'monthNames'    => $this->buildMonthNames($request->getLocale()),
            'yearOptions'   => $this->buildYearOptions(),
            'pageSize'      => $limit,
            'pendingImport' => $pendingImport,
            'importPersons' => $importPersons,
        ]);
    }

    /** @return array<int, string> */
    private function buildMonthNames(string $locale): array
    {
        $names = [];
        for ($m = 1; $m <= 12; $m++) {
            $date = new \DateTimeImmutable(sprintf('2000-%02d-01', $m));
            if (class_exists(\IntlDateFormatter::class)) {
                $fmt      = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'LLLL');
                $names[$m] = ucfirst((string) $fmt->format(\DateTime::createFromImmutable($date)));
            } else {
                $names[$m] = $date->format('F');
            }
        }
        return $names;
    }

    /** @return int[] */
    private function buildYearOptions(): array
    {
        return range(2026, (int) (new \DateTimeImmutable())->format('Y') + 1);
    }

    /** Schritt 1: PDF hochladen, parsen, matchen → Review-Session */
    #[Route('/import', name: 'external_task_import', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function import(Request $request): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($request->isMethod('GET')) {
            return $this->render('external_task/import_form.html.twig');
        }

        if (!$this->isCsrfTokenValid('external-task-import', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('external_task_index');
        }

        $file = $request->files->get('pdf_file');
        if ($file === null || !$file->isValid()) {
            $this->addFlash('error', 'import.no_file');
            return $this->redirectToRoute('external_task_import');
        }

        if ($file->getMimeType() !== 'application/pdf' && $file->getClientOriginalExtension() !== 'pdf') {
            $this->addFlash('error', 'import.file_not_pdf');
            return $this->redirectToRoute('external_task_import');
        }

        $persons = $assembly
            ? $this->personRepository->findByAssembly($assembly->getId())
            : [];

        try {
            $items = $this->pdfImporter->parseAndMatch($file->getPathname(), $persons);
        } catch (\Throwable $e) {
            $this->logger->error('PDF-Import fehlgeschlagen.', [
                'file'      => $file->getClientOriginalName(),
                'exception' => $e->getMessage(),
                'file_path' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $this->addFlash('error', 'import.file_empty');
            return $this->redirectToRoute('external_task_import');
        }

        if (empty($items)) {
            $this->addFlash('warning', 'import.no_events');
            return $this->redirectToRoute('external_task_import');
        }

        $request->getSession()->set('external_task_import_pending', [
            'assembly_id' => $assembly?->getId(),
            'items'       => $items,
        ]);

        return $this->redirectToRoute('external_task_index');
    }

    /** Schritt 2: Review-Modal (Redirect auf Index, der das Modal öffnet) */
    #[Route('/import/review', name: 'external_task_import_review', methods: ['GET'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function importReview(Request $request): Response
    {
        return $this->redirectToRoute('external_task_index');
    }

    /** Schritt 3: Bestätigte Einträge als ExternalTask speichern */
    #[Route('/import/confirm', name: 'external_task_import_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function importConfirm(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('external-task-import-confirm', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('external_task_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $pending = $request->getSession()->get('external_task_import_pending');
        if ($pending === null || $assembly === null) {
            return $this->redirectToRoute('external_task_index');
        }

        $selected  = $request->request->all('selected') ?: [];
        $personIds = $request->request->all('person_id') ?: [];
        $persons   = $this->personRepository->findByAssembly($assembly->getId());
        $personMap = [];
        foreach ($persons as $p) {
            $personMap[$p->getId()] = $p;
        }

        $imported = 0;
        foreach ($pending['items'] as $i => $item) {
            if (!in_array((string) $i, $selected, true)) {
                continue;
            }
            $personId = (int) ($personIds[$i] ?? 0);
            if ($personId === 0 || !isset($personMap[$personId])) {
                continue;
            }

            $date    = new \DateTimeImmutable($item['date'] ?? $item['date_start'] ?? 'today');
            $date    = $this->assemblyService->resolveAssemblyDate($date, $assembly);
            $day     = $this->findOrCreateDay($assembly, $date);

            $existing = $this->externalTaskRepository->findByPersonAndDay($personId, $day->getId());
            if ($existing !== null) {
                continue;
            }

            $et = new ExternalTask();
            $et->setPerson($personMap[$personId]);
            $et->setDay($day);
            $et->setDescription($item['description'] ?: $item['summary']);
            $this->entityManager->persist($et);
            $imported++;
        }

        $this->entityManager->flush();
        $request->getSession()->remove('external_task_import_pending');

        $this->addFlash('success', sprintf('%d externe Aufgabe(n) importiert.', $imported));
        return $this->redirectToRoute('external_task_index');
    }

    /**
     * Gibt den Planungstag für ein Datum zurück.
     *
     * Legt einen neuen Day an und speichert ihn, wenn noch keiner existiert (DB-Seiteneffekt).
     */
    private function findOrCreateDay(\App\Entity\Assembly $assembly, \DateTimeImmutable $date): Day
    {
        $day = $this->dayRepository->findByAssemblyAndDate($assembly->getId(), $date);
        if ($day === null) {
            $day = new Day();
            $day->setAssembly($assembly);
            $day->setDate($date);
            $this->entityManager->persist($day);
            $this->entityManager->flush();
        }
        return $day;
    }

    #[Route('/new', name: 'external_task_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);
        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $persons = $assembly
            ? $this->personRepository->findByAssembly($assembly->getId(), $departmentIds)
            : [];
        $days = [];
        if ($assembly !== null) {
            $from = new \DateTimeImmutable('today');
            $to = $from->modify('+60 days');
            $days = $this->dayRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to);
        }

        $externalTask = new ExternalTask();
        $form = $this->createForm(ExternalTaskType::class, $externalTask, [
            'persons' => $persons,
            'days' => $days,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->externalTaskService->save($externalTask);
            $this->addFlash('success', 'flash.external_task.created');
            return $this->redirectToRoute('external_task_index');
        }

        return $this->render('external_task/form.html.twig', [
            'form' => $form,
            'title' => 'Neue externe Aufgabe',
        ]);
    }

    #[Route('/{id}/edit', name: 'external_task_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ExternalTask $externalTask): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->planerScope->isActive($user)) {
            $ids = $this->planerScope->getDepartmentIds($user);
            $personDeptIds = $externalTask->getPerson()->getTasks()->map(fn($t) => $t->getDepartment()->getId())->toArray();
            if (empty(array_intersect($ids, $personDeptIds))) {
                throw $this->createAccessDeniedException();
            }
        }

        $assembly = $this->assemblyContext->getActiveAssembly($user);
        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $persons = $assembly
            ? $this->personRepository->findByAssembly($assembly->getId(), $departmentIds)
            : [];
        $days = [];
        if ($assembly !== null) {
            $from = new \DateTimeImmutable('today');
            $to = $from->modify('+60 days');
            $days = $this->dayRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to);
        }

        $form = $this->createForm(ExternalTaskType::class, $externalTask, [
            'persons' => $persons,
            'days' => $days,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->externalTaskService->save($externalTask);
            $this->addFlash('success', 'flash.external_task.saved');
            return $this->redirectToRoute('external_task_index');
        }

        return $this->render('external_task/form.html.twig', [
            'form' => $form,
            'title' => 'Externe Aufgabe bearbeiten',
        ]);
    }

    #[Route('/{id}/delete', name: 'external_task_delete', methods: ['POST'])]
    public function delete(Request $request, ExternalTask $externalTask): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($this->planerScope->isActive($user)) {
            $ids = $this->planerScope->getDepartmentIds($user);
            $personDeptIds = $externalTask->getPerson()->getTasks()->map(fn($t) => $t->getDepartment()->getId())->toArray();
            if (empty(array_intersect($ids, $personDeptIds))) {
                throw $this->createAccessDeniedException();
            }
        }

        if ($this->isCsrfTokenValid('delete-external-task-' . $externalTask->getId(), $request->getPayload()->getString('_token'))) {
            $this->externalTaskService->delete($externalTask);
            $this->addFlash('success', 'flash.external_task.deleted');
        }
        return $this->redirectToRoute('external_task_index');
    }
}
