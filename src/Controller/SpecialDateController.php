<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SpecialDate;
use App\Entity\User;
use App\Form\SpecialDateType;
use App\Repository\SpecialDateRepository;
use App\Service\AssemblyContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/special-dates')]
#[IsGranted('ROLE_ASSEMBLY_ADMIN')]
class SpecialDateController extends AbstractController
{
    public function __construct(
        private readonly SpecialDateRepository $specialDateRepository,
        private readonly AssemblyContext $assemblyContext,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'special_date_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $q    = trim($request->query->getString('q'));
        $page = max(1, $request->query->getInt('page', 1));
        $sort = $request->query->getString('sort', 'startDate');
        $dir  = $request->query->getString('dir', 'ASC');

        $hasFilter    = $request->query->has('year');
        $selectedYear = $hasFilter ? $request->query->getInt('year', 0) : (int) (new \DateTimeImmutable())->format('Y');

        $limit  = $assembly?->getPageSize() ?? 10;
        $result = $assembly
            ? $this->specialDateRepository->findFiltered($assembly->getId(), $q, $page, $limit, $sort, $dir, $selectedYear)
            : ['items' => [], 'total' => 0, 'pages' => 1];

        $newSpecialDate = new SpecialDate();
        if ($assembly !== null) {
            $newSpecialDate->setAssembly($assembly);
        }
        $newSpecialDateForm = $this->createForm(SpecialDateType::class, $newSpecialDate, [
            'action' => $this->generateUrl('special_date_new'),
        ]);

        return $this->render('special_date/index.html.twig', [
            'specialDates'      => $result['items'],
            'total'             => $result['total'],
            'pages'             => $result['pages'],
            'page'              => $page,
            'q'                 => $q,
            'sort'              => $sort,
            'dir'               => $dir,
            'selectedYear'      => $selectedYear,
            'yearOptions'       => range(2026, (int) (new \DateTimeImmutable())->format('Y') + 1),
            'newSpecialDateForm' => $newSpecialDateForm,
        ]);
    }

    #[Route('/new', name: 'special_date_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            $this->addFlash('warning', 'planning.no_assembly');
            return $this->redirectToRoute('special_date_index');
        }

        $specialDate = new SpecialDate();
        $specialDate->setAssembly($assembly);

        $form = $this->createForm(SpecialDateType::class, $specialDate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($specialDate);
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.special_date.created');
            return $this->redirectToRoute('special_date_index');
        }

        return $this->render('special_date/form.html.twig', [
            'form' => $form,
            'title' => 'title.special_date.new',
        ]);
    }

    #[Route('/{id}/edit', name: 'special_date_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SpecialDate $specialDate): Response
    {
        $form = $this->createForm(SpecialDateType::class, $specialDate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.special_date.saved');
            return $this->redirectToRoute('special_date_index');
        }

        return $this->render('special_date/form.html.twig', [
            'form' => $form,
            'title' => 'title.special_date.edit',
            'specialDate' => $specialDate,
        ]);
    }

    #[Route('/{id}/delete', name: 'special_date_delete', methods: ['POST'])]
    public function delete(Request $request, SpecialDate $specialDate): Response
    {
        if ($this->isCsrfTokenValid('delete-special-date-' . $specialDate->getId(), $request->getPayload()->getString('_token'))) {
            $this->entityManager->remove($specialDate);
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.special_date.deleted');
        }

        return $this->redirectToRoute('special_date_index');
    }
}
