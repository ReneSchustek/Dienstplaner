<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Person;
use App\Entity\User;
use App\Form\PersonType;
use App\Repository\PersonRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\AssemblyContext;
use App\Service\PersonService;
use App\Service\PlanerScope;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Verwaltung von Personen innerhalb einer Versammlung (CRUD).
 *
 * Zugänglich für Planer und Administratoren.
 */
#[Route('/persons')]
#[IsGranted('ROLE_PLANER')]
class PersonController extends AbstractController
{
    public function __construct(
        private readonly PersonRepository $personRepository,
        private readonly PersonService $personService,
        private readonly TaskRepository $taskRepository,
        private readonly AssemblyContext $assemblyContext,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly UserService $userService,
        private readonly PlanerScope $planerScope,
    ) {}

    #[Route('', name: 'person_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $q    = trim($request->query->getString('q'));
        $page = max(1, $request->query->getInt('page', 1));
        $sort = $request->query->getString('sort', 'name');
        $dir  = $request->query->getString('dir', 'ASC');

        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $limit  = $assembly?->getPageSize() ?? 10;
        $result = $assembly
            ? $this->personRepository->findFiltered($assembly->getId(), $q, $page, $limit, $sort, $dir, $departmentIds)
            : ['items' => [], 'total' => 0, 'pages' => 1];

        return $this->render('person/index.html.twig', [
            'persons' => $result['items'],
            'total'   => $result['total'],
            'pages'   => $result['pages'],
            'page'    => $page,
            'q'       => $q,
            'sort'    => $sort,
            'dir'     => $dir,
        ]);
    }

    #[Route('/new', name: 'person_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);
        $departmentIds = $this->planerScope->isActive($user) ? $this->planerScope->getDepartmentIds($user) : null;
        $availableTasks = $assembly ? $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds) : [];

        $person = new Person();
        $form = $this->createForm(PersonType::class, $person, [
            'assembly_fixed' => false,
            'available_tasks' => $availableTasks,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($assembly !== null) {
                $existing = $this->personRepository->findByAssembly($assembly->getId());
                $newNorm  = $this->normalizePersonName($person->getName());
                foreach ($existing as $other) {
                    if ($this->normalizePersonName($other->getName()) === $newNorm) {
                        $this->addFlash('error', 'flash.person.duplicate_name');
                        return $this->render('person/form.html.twig', [
                            'form' => $form,
                            'title' => 'Neue Person',
                        ]);
                    }
                }
            }
            $this->personService->save($person);
            $this->addFlash('success', 'flash.person.created');
            return $this->redirectToRoute('person_index');
        }

        return $this->render('person/form.html.twig', [
            'form' => $form,
            'title' => 'Neue Person',
        ]);
    }

    #[Route('/{id}/edit', name: 'person_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Person $person): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($this->planerScope->isActive($currentUser)) {
            $ids = $this->planerScope->getDepartmentIds($currentUser);
            $personDeptIds = $person->getTasks()->map(fn($t) => $t->getDepartment()->getId())->toArray();
            if (empty(array_intersect($ids, $personDeptIds))) {
                throw $this->createAccessDeniedException();
            }
        }

        $assembly = $this->assemblyContext->getActiveAssembly($currentUser);
        $departmentIds = $this->planerScope->isActive($currentUser) ? $this->planerScope->getDepartmentIds($currentUser) : null;
        $availableTasks = $assembly ? $this->taskRepository->findByAssembly($assembly->getId(), $departmentIds) : [];

        $form = $this->createForm(PersonType::class, $person, [
            'assembly_fixed' => true,
            'available_tasks' => $availableTasks,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($assembly !== null) {
                $newNorm  = $this->normalizePersonName($person->getName());
                $existing = $this->personRepository->findByAssembly($assembly->getId());
                foreach ($existing as $other) {
                    if ($other->getId() !== $person->getId() && $this->normalizePersonName($other->getName()) === $newNorm) {
                        $this->addFlash('error', 'flash.person.duplicate_name');
                        $linkedUser = $this->userRepository->findByPerson($person->getId());
                        return $this->render('person/form.html.twig', [
                            'form'       => $form,
                            'title'      => 'Person bearbeiten',
                            'person'     => $person,
                            'linkedUser' => $linkedUser,
                        ]);
                    }
                }
            }
            $this->personService->save($person);
            $this->addFlash('success', 'flash.person.saved');
            return $this->redirectToRoute('person_index');
        }

        $linkedUser = $this->userRepository->findByPerson($person->getId());

        return $this->render('person/form.html.twig', [
            'form'       => $form,
            'title'      => 'Person bearbeiten',
            'person'     => $person,
            'linkedUser' => $linkedUser,
        ]);
    }

    #[Route('/{id}/calendar-token/generate', name: 'person_calendar_token_generate', methods: ['POST'])]
    public function generateCalendarToken(Request $request, Person $person): Response
    {
        if (!$this->isCsrfTokenValid('person-calendar-token-' . $person->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
        }

        $linkedUser = $this->userRepository->findByPerson($person->getId());
        if ($linkedUser === null) {
            $this->addFlash('warning', 'flash.user.no_calendar_token');
            return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
        }

        $token = bin2hex(random_bytes(32));
        $linkedUser->setCalendarToken($token);
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.calendar_token.generated');
        return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
    }

    #[Route('/{id}/calendar-token/send', name: 'person_calendar_token_send', methods: ['POST'])]
    public function sendCalendarToken(Request $request, Person $person): Response
    {
        if (!$this->isCsrfTokenValid('person-calendar-token-send-' . $person->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
        }

        $linkedUser = $this->userRepository->findByPerson($person->getId());
        if ($linkedUser === null || !$linkedUser->getCalendarToken()) {
            $this->addFlash('warning', 'flash.user.no_calendar_token');
            return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
        }

        $senderEmail = $_ENV['MAILER_SENDER_EMAIL'] ?? 'noreply@dienstplaner.local';
        $senderName  = $_ENV['MAILER_SENDER_NAME'] ?? 'Dienstplaner';

        $calendarUrl = $this->generateUrl(
            'calendar_token_view',
            ['token' => $linkedUser->getCalendarToken()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $body = $this->renderView('email/calendar_link.html.twig', [
            'calendarUrl' => $calendarUrl,
            'person'      => $person,
        ]);

        try {
            $email = (new Email())
                ->from(new Address($senderEmail, $senderName))
                ->to($linkedUser->getEmail())
                ->subject('[Dienstplaner] Dein persönlicher Kalenderlink')
                ->html($body);

            $this->mailer->send($email);
            $this->addFlash('success', 'flash.user.calendar_link_sent');
        } catch (\Throwable $e) {
            $this->logger->error('Calendar link send failed', ['error' => $e->getMessage()]);
            $this->addFlash('error', 'flash.mail.error');
        }

        return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
    }

    #[Route('/{id}/create-user', name: 'person_create_user', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function createUser(Request $request, Person $person): Response
    {
        if (!$this->isCsrfTokenValid('person-create-user-' . $person->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
        }

        $existingUser = $this->userRepository->findByPerson($person->getId());
        if ($existingUser !== null) {
            $this->addFlash('warning', 'flash.user.already_linked');
            return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
        }

        $email         = trim($request->request->getString('email'));
        $plainPassword = trim($request->request->getString('plainPassword'));
        $role          = $request->request->getString('role', 'ROLE_USER');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'flash.user.invalid_email');
            return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
        }

        if (strlen($plainPassword) < 8) {
            $this->addFlash('error', 'flash.password.too_short');
            return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
        }

        $allowedRoles = ['ROLE_USER', 'ROLE_PLANER', 'ROLE_ASSEMBLY_ADMIN'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'ROLE_USER';
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $assembly    = $this->assemblyContext->getActiveAssembly($currentUser);

        $newUser = new User();
        $newUser->setEmail($email);
        $newUser->setName($person->getName());
        $newUser->setRole($role);
        $newUser->setAssembly($assembly);
        $newUser->setPerson($person);
        $newUser->setForcePasswordChange(true);

        $this->userService->createUser($newUser, $plainPassword);

        if ($person->getEmail() === null || $person->getEmail() === '') {
            $person->setEmail($email);
            $this->entityManager->flush();
        }

        $notify = $request->request->getBoolean('notify', true);

        if ($notify) {
            if ($this->sendUserInvitation($newUser, $plainPassword)) {
                $this->addFlash('success', 'flash.user.invitation_sent');
            } else {
                $this->addFlash('success', 'flash.user.created');
                $this->addFlash('warning', 'flash.mail.error');
            }
        } else {
            $this->addFlash('success', 'flash.user.created');
        }

        return $this->redirectToRoute('person_edit', ['id' => $person->getId()]);
    }

    /**
     * Sendet die Einladungs-E-Mail an den neuen Benutzer.
     *
     * Gibt true zurück wenn die E-Mail erfolgreich versendet wurde, false bei einem Fehler.
     */
    private function sendUserInvitation(User $user, string $plainPassword): bool
    {
        $assemblyName = $user->getAssembly()?->getName() ?? 'Dienstplaner';
        $loginUrl     = $this->generateUrl('security_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $senderEmail  = $_ENV['MAILER_SENDER_EMAIL'] ?? 'noreply@dienstplaner.local';
        $senderName   = $_ENV['MAILER_SENDER_NAME'] ?? 'Dienstplaner';

        $html = $this->renderView('email/user_invitation.html.twig', [
            'user'          => $user,
            'plainPassword' => $plainPassword,
            'assemblyName'  => $assemblyName,
            'loginUrl'      => $loginUrl,
        ]);

        $email = (new Email())
            ->from(new Address($senderEmail, $senderName))
            ->to($user->getEmail())
            ->subject('Dein Zugang zum Dienstplaner – ' . $assemblyName)
            ->html($html);

        try {
            $this->mailer->send($email);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Einladungs-E-Mail konnte nicht gesendet werden', [
                'recipient' => $user->getEmail(),
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Normalisiert einen Personennamen für den Duplikatvergleich.
     *
     * Sortiert die Namenstokens alphabetisch, damit "Müller, Hans" und "Hans Müller" gleich behandelt werden.
     */
    private function normalizePersonName(string $name): string
    {
        // "Müller, Hans" → "hans muller" === "Hans Müller" normalized
        $name = str_replace(',', ' ', $name);
        if (function_exists('transliterator_transliterate')) {
            $name = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        } else {
            $name = mb_strtolower($name);
        }
        $tokens = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        sort($tokens);
        return implode(' ', $tokens);
    }

    #[Route('/{id}/delete', name: 'person_delete', methods: ['POST'])]
    public function delete(Request $request, Person $person): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($this->planerScope->isActive($user)) {
            $ids = $this->planerScope->getDepartmentIds($user);
            $personDeptIds = $person->getTasks()->map(fn($t) => $t->getDepartment()->getId())->toArray();
            if (empty(array_intersect($ids, $personDeptIds))) {
                throw $this->createAccessDeniedException();
            }
        }

        if ($this->isCsrfTokenValid('delete-person-' . $person->getId(), $request->getPayload()->getString('_token'))) {
            $this->personService->delete($person);
            $this->addFlash('success', 'flash.person.deleted');
        }
        return $this->redirectToRoute('person_index');
    }
}
