<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Department;
use App\Entity\User;
use App\Form\DepartmentType;
use App\Repository\DepartmentRepository;
use App\Service\AssemblyContext;
use App\Service\DepartmentService;
use App\Service\PlanerScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Verwaltung von Abteilungen innerhalb einer Versammlung (CRUD).
 *
 * Zugänglich für Planer und Administratoren.
 */
#[Route('/departments')]
#[IsGranted('ROLE_PLANER')]
class DepartmentController extends AbstractController
{
    public function __construct(
        private readonly DepartmentRepository $departmentRepository,
        private readonly DepartmentService $departmentService,
        private readonly AssemblyContext $assemblyContext,
        private readonly PlanerScope $planerScope,
    ) {}

    #[Route('', name: 'department_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($this->planerScope->isActive($user)) {
            $ids = $this->planerScope->getDepartmentIds($user);
            $departments = empty($ids) ? [] : $this->departmentRepository->findBy(['id' => $ids], ['name' => 'ASC']);
        } else {
            $departments = $assembly
                ? $this->departmentRepository->findByAssembly($assembly->getId())
                : [];
        }

        $newDept = new Department();
        if ($assembly !== null) {
            $newDept->setAssembly($assembly);
        }
        $newDeptForm = $this->createForm(DepartmentType::class, $newDept, [
            'assembly_fixed' => true,
            'action' => $this->generateUrl('department_new'),
        ]);

        return $this->render('department/index.html.twig', [
            'departments' => $departments,
            'readOnly'    => $this->planerScope->isActive($user),
            'newDeptForm' => $newDeptForm,
        ]);
    }

    #[Route('/new', name: 'department_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function new(Request $request): Response
    {
        $department = new Department();
        $form = $this->createForm(DepartmentType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->departmentService->save($department);
            $this->addFlash('success', 'flash.department.created');
            return $this->redirectToRoute('department_index');
        }

        return $this->render('department/form.html.twig', [
            'form' => $form,
            'title' => 'Neue Abteilung',
        ]);
    }

    #[Route('/{id}/edit', name: 'department_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function edit(Request $request, Department $department): Response
    {
        $form = $this->createForm(DepartmentType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->departmentService->save($department);
            $this->addFlash('success', 'flash.department.saved');
            return $this->redirectToRoute('department_index');
        }

        return $this->render('department/form.html.twig', [
            'form' => $form,
            'title' => 'Abteilung bearbeiten',
            'department' => $department,
        ]);
    }

    #[Route('/{id}/delete', name: 'department_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function delete(Request $request, Department $department): Response
    {
        if ($this->isCsrfTokenValid('delete-department-' . $department->getId(), $request->getPayload()->getString('_token'))) {
            $this->departmentService->delete($department);
            $this->addFlash('success', 'flash.department.deleted');
        }
        return $this->redirectToRoute('department_index');
    }
}
