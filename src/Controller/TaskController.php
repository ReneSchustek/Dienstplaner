<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Form\TaskType;
use App\Repository\TaskRepository;
use App\Service\AssemblyContext;
use App\Service\PlanerScope;
use App\Service\TaskService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Verwaltung von Aufgaben innerhalb einer Versammlung (CRUD).
 *
 * Zugänglich für Planer und Administratoren.
 */
#[Route('/tasks')]
#[IsGranted('ROLE_PLANER')]
class TaskController extends AbstractController
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly TaskService $taskService,
        private readonly AssemblyContext $assemblyContext,
        private readonly PlanerScope $planerScope,
    ) {}

    #[Route('', name: 'task_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $tasks = $assembly
            ? $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds)
            : [];

        $newTaskForm = $this->createForm(TaskType::class, new Task(), [
            'action' => $this->generateUrl('task_new'),
        ]);

        return $this->render('task/index.html.twig', [
            'tasks'       => $tasks,
            'newTaskForm' => $newTaskForm,
        ]);
    }

    #[Route('/new', name: 'task_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function new(Request $request): Response
    {
        $task = new Task();
        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->taskService->save($task);
            $this->addFlash('success', 'flash.task.created');
            return $this->redirectToRoute('task_index');
        }

        return $this->render('task/form.html.twig', [
            'form' => $form,
            'title' => 'Neue Aufgabe',
        ]);
    }

    #[Route('/{id}/edit', name: 'task_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function edit(Request $request, Task $task): Response
    {
        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->taskService->save($task);
            $this->addFlash('success', 'flash.task.saved');
            return $this->redirectToRoute('task_index');
        }

        return $this->render('task/form.html.twig', [
            'form' => $form,
            'title' => 'Aufgabe bearbeiten',
            'task' => $task,
        ]);
    }

    #[Route('/{id}/delete', name: 'task_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function delete(Request $request, Task $task): Response
    {
        if ($this->isCsrfTokenValid('delete-task-' . $task->getId(), $request->getPayload()->getString('_token'))) {
            $this->taskService->delete($task);
            $this->addFlash('success', 'flash.task.deleted');
        }
        return $this->redirectToRoute('task_index');
    }
}
