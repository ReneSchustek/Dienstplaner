<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Absence;
use App\Entity\Assembly;
use App\Entity\User;
use App\Form\AbsenceType;
use App\Repository\AbsenceRepository;
use App\Repository\PersonRepository;
use App\Service\AbsenceService;
use App\Service\AssemblyContext;
use App\Service\IcsMatcherService;
use App\Service\PlanerScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/absences')]
#[IsGranted('ROLE_PLANER')]
class AbsenceController extends AbstractController
{
    public function __construct(
        private readonly AbsenceRepository $absenceRepository,
        private readonly PersonRepository $personRepository,
        private readonly AbsenceService $absenceService,
        private readonly AssemblyContext $assemblyContext,
        private readonly IcsMatcherService $icsMatcher,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanerScope $planerScope,
    ) {}

    #[Route('', name: 'absence_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $q    = trim($request->query->getString('q'));
        $page = max(1, $request->query->getInt('page', 1));
        $sort = $request->query->getString('sort', 'startDate');
        $dir  = $request->query->getString('dir', 'ASC');

        $now = new \DateTimeImmutable();
        $hasFilter = $request->query->has('year');
        $selectedMonth = $hasFilter ? $request->query->getInt('month', 0) : (int) $now->format('n');
        $selectedYear  = $hasFilter ? $request->query->getInt('year', 0)  : (int) $now->format('Y');

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $limit  = $assembly?->getPageSize() ?? 10;
        $result = $assembly
            ? $this->absenceRepository->findFiltered($assembly->getId(), $q, $page, $limit, $sort, $dir, $selectedMonth, $selectedYear, $departmentIds)
            : ['items' => [], 'total' => 0, 'pages' => 1];

        return $this->render('absence/index.html.twig', [
            'absences'      => $result['items'],
            'assembly'      => $assembly,
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
        ]);
    }

    /** @return array<int, string> Monatsnummern 1–12 => lokalisierter Monatsname */
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

    /** @return int[] Jahre ab 2026 bis zum Folgejahr */
    private function buildYearOptions(): array
    {
        return range(2026, (int) (new \DateTimeImmutable())->format('Y') + 1);
    }

    /** Schritt 1: TeamUp-ICS holen, parsen, matchen → Review-Session setzen */
    #[Route('/teamup-import', name: 'absence_teamup_import', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function teamupImport(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('absence-teamup-import', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('absence_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null || !$assembly->getTeamupCalendarUrl()) {
            $this->addFlash('warning', 'flash.teamup.no_url');
            return $this->redirectToRoute('absence_index');
        }

        try {
            $icsContent = $this->httpClient->request('GET', $assembly->getTeamupCalendarUrl())->getContent();
        } catch (\Throwable) {
            $this->addFlash('error', 'flash.teamup.error.fetch');
            return $this->redirectToRoute('absence_index');
        }

        $persons = $this->personRepository->findByAssembly($assembly->getId());
        $items   = $this->icsMatcher->parseAndMatch($icsContent, $persons);

        $request->getSession()->set('absence_import_pending', [
            'assembly_id' => $assembly->getId(),
            'items'       => $items,
        ]);

        return $this->redirectToRoute('absence_teamup_import_review');
    }

    /** Schritt 2: Review-Tabelle anzeigen */
    #[Route('/teamup-import/review', name: 'absence_teamup_import_review', methods: ['GET'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function teamupImportReview(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $pending = $request->getSession()->get('absence_import_pending');
        if ($pending === null || ($assembly !== null && $pending['assembly_id'] !== $assembly->getId())) {
            return $this->redirectToRoute('absence_index');
        }

        $persons = $assembly
            ? $this->personRepository->findByAssembly($assembly->getId())
            : [];

        return $this->render('absence/teamup_import_review.html.twig', [
            'items'   => $pending['items'],
            'persons' => $persons,
        ]);
    }

    /** Schritt 3: Bestätigte Einträge als Abwesenheiten speichern */
    #[Route('/teamup-import/confirm', name: 'absence_teamup_import_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function teamupImportConfirm(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('absence-import-confirm', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('absence_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $pending = $request->getSession()->get('absence_import_pending');
        if ($pending === null || $assembly === null) {
            return $this->redirectToRoute('absence_index');
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

            $startDate = new \DateTimeImmutable($item['date_start']);
            $endDate   = new \DateTimeImmutable($item['date_end']);

            $existing = $this->absenceRepository->findAbsencesForPersonOnDate($personId, $startDate);
            if (!empty($existing)) {
                continue;
            }

            $absence = new Absence();
            $absence->setPerson($personMap[$personId]);
            $absence->setStartDate($startDate);
            $absence->setEndDate($endDate);
            $absence->setNote($item['summary'] ?? null);
            $this->entityManager->persist($absence);
            $imported++;
        }

        $this->entityManager->flush();
        $request->getSession()->remove('absence_import_pending');

        $this->addFlash('success', sprintf('%d Abwesenheit(en) importiert.', $imported));
        return $this->redirectToRoute('absence_index');
    }

    #[Route('/new', name: 'absence_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);
        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $persons = $assembly
            ? $this->personRepository->findByAssembly($assembly->getId(), $departmentIds)
            : [];

        $absence = new Absence();
        $form = $this->createForm(AbsenceType::class, $absence, ['persons' => $persons]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->absenceService->save($absence);
            $this->addFlash('success', 'flash.absence.created');
            return $this->redirectToRoute('absence_index');
        }

        return $this->render('absence/form.html.twig', [
            'form' => $form,
            'title' => 'Neue Abwesenheit',
        ]);
    }

    #[Route('/{id}/edit', name: 'absence_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Absence $absence): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->planerScope->isActive($user)) {
            $ids = $this->planerScope->getDepartmentIds($user);
            $personDeptIds = $absence->getPerson()->getTasks()->map(fn($t) => $t->getDepartment()->getId())->toArray();
            if (empty(array_intersect($ids, $personDeptIds))) {
                throw $this->createAccessDeniedException();
            }
        }

        $assembly = $this->assemblyContext->getActiveAssembly($user);
        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $persons = $assembly
            ? $this->personRepository->findByAssembly($assembly->getId(), $departmentIds)
            : [];

        $form = $this->createForm(AbsenceType::class, $absence, ['persons' => $persons]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->absenceService->save($absence);
            $this->addFlash('success', 'flash.absence.saved');
            return $this->redirectToRoute('absence_index');
        }

        return $this->render('absence/form.html.twig', [
            'form' => $form,
            'title' => 'Abwesenheit bearbeiten',
        ]);
    }

    #[Route('/{id}/delete', name: 'absence_delete', methods: ['POST'])]
    public function delete(Request $request, Absence $absence): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($this->planerScope->isActive($user)) {
            $ids = $this->planerScope->getDepartmentIds($user);
            $personDeptIds = $absence->getPerson()->getTasks()->map(fn($t) => $t->getDepartment()->getId())->toArray();
            if (empty(array_intersect($ids, $personDeptIds))) {
                throw $this->createAccessDeniedException();
            }
        }

        if ($this->isCsrfTokenValid('delete-absence-' . $absence->getId(), $request->getPayload()->getString('_token'))) {
            $this->absenceService->delete($absence);
            $this->addFlash('success', 'flash.absence.deleted');
        }
        return $this->redirectToRoute('absence_index');
    }
}
