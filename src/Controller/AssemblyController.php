<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Assembly;
use App\Form\AssemblyType;
use App\Repository\AssemblyRepository;
use App\Security\Voter\AssemblyVoter;
use App\Service\AssemblyService;
use App\Service\IcsImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Verwaltung von Versammlungen (CRUD).
 *
 * Nur für Administratoren. Ermöglicht den Wechsel in eine fremde
 * Versammlung zur Einsicht und Verwaltung.
 */
#[Route('/assemblies')]
#[IsGranted('ROLE_ASSEMBLY_ADMIN')]
class AssemblyController extends AbstractController
{
    public function __construct(
        private readonly AssemblyRepository $assemblyRepository,
        private readonly AssemblyService $assemblyService,
        private readonly IcsImportService $icsImportService,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'assembly_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): Response
    {
        return $this->render('assembly/index.html.twig', [
            'assemblies' => $this->assemblyRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'assembly_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $assembly = new Assembly();
        $form = $this->createForm(AssemblyType::class, $assembly);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assemblyService->save($assembly);
            $this->addFlash('success', 'flash.assembly.created');
            return $this->redirectToRoute('assembly_index');
        }

        return $this->render('assembly/form.html.twig', [
            'form' => $form,
            'title' => 'Neue Versammlung',
        ]);
    }

    #[Route('/{id}/edit', name: 'assembly_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Assembly $assembly): Response
    {
        $this->denyAccessUnlessGranted(AssemblyVoter::EDIT, $assembly);

        $form = $this->createForm(AssemblyType::class, $assembly);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assemblyService->save($assembly);
            $this->addFlash('success', 'flash.assembly.saved');
            return $this->redirectToRoute('assembly_index');
        }

        return $this->render('assembly/form.html.twig', [
            'form' => $form,
            'title' => 'Versammlung bearbeiten',
            'assembly' => $assembly,
        ]);
    }

    #[Route('/{id}/delete', name: 'assembly_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Assembly $assembly): Response
    {
        if ($this->isCsrfTokenValid('delete-assembly-' . $assembly->getId(), $request->getPayload()->getString('_token'))) {
            $this->assemblyService->delete($assembly);
            $this->addFlash('success', 'flash.assembly.deleted');
        }
        return $this->redirectToRoute('assembly_index');
    }

    #[Route('/{id}/teamup-import', name: 'assembly_teamup_import', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function teamupImport(Request $request, Assembly $assembly): Response
    {
        if (!$this->isCsrfTokenValid('teamup-import-' . $assembly->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'flash.teamup.error');
            return $this->redirectToRoute('assembly_edit', ['id' => $assembly->getId()]);
        }

        if (!$assembly->getTeamupCalendarUrl()) {
            $this->addFlash('warning', 'flash.teamup.no_url');
            return $this->redirectToRoute('assembly_edit', ['id' => $assembly->getId()]);
        }

        try {
            $count = $this->icsImportService->importFromAssembly($assembly);
            $this->addFlash('success', $this->translator->trans('flash.teamup.imported', ['%count%' => $count]));
        } catch (\Throwable) {
            $this->addFlash('error', 'flash.teamup.error.fetch');
        }

        return $this->redirectToRoute('assembly_edit', ['id' => $assembly->getId()]);
    }
}
