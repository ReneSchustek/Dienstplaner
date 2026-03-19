<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\DepartmentRepository;
use App\Repository\UserRepository;
use App\Service\AssemblyContext;
use App\Service\UserService;
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

#[Route('/admin/users')]
#[IsGranted('ROLE_ASSEMBLY_ADMIN')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserService $userService,
        private readonly AssemblyContext $assemblyContext,
        private readonly DepartmentRepository $departmentRepository,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'admin_user_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        $q    = trim($request->query->getString('q'));
        $page = max(1, $request->query->getInt('page', 1));

        $limit  = $assembly?->getPageSize() ?? 10;
        $result = $assembly
            ? $this->userRepository->findFiltered($assembly->getId(), $q, $page, $limit)
            : ['items' => [], 'total' => 0, 'pages' => 1];

        $availableDepartments = $assembly ? $this->departmentRepository->findByAssembly($assembly->getId()) : [];
        $newUserForm = $this->createForm(UserType::class, new User(), [
            'is_new' => true,
            'available_departments' => $availableDepartments,
            'action' => $this->generateUrl('admin_user_new'),
        ]);

        return $this->render('admin/user/index.html.twig', [
            'users'       => $result['items'],
            'total'       => $result['total'],
            'pages'       => $result['pages'],
            'page'        => $page,
            'q'           => $q,
            'newUserForm' => $newUserForm,
        ]);
    }

    #[Route('/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($currentUser);
        $availableDepartments = $assembly ? $this->departmentRepository->findByAssembly($assembly->getId()) : [];

        $user = new User();
        $form = $this->createForm(UserType::class, $user, [
            'is_new' => true,
            'available_departments' => $availableDepartments,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $this->userService->inviteUser($user);
            if ($this->sendInvitationEmail($user, $plainPassword)) {
                $this->addFlash('success', 'flash.user.invitation_sent');
            } else {
                $this->addFlash('success', 'flash.user.created');
                $this->addFlash('warning', 'flash.mail.error');
            }
            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'title' => 'Neuer Benutzer',
            'user' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($currentUser);

        if ($assembly !== null && $user->getAssembly()?->getId() !== $assembly->getId()) {
            throw $this->createAccessDeniedException();
        }

        $availableDepartments = $assembly ? $this->departmentRepository->findByAssembly($assembly->getId()) : [];

        $form = $this->createForm(UserType::class, $user, [
            'available_departments' => $availableDepartments,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->userService->updateUser($user, $form->get('plainPassword')->getData());
            $this->addFlash('success', 'flash.user.saved');
            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'title' => 'Benutzer bearbeiten',
            'user' => $user,
        ]);
    }

    #[Route('/{id}/reset-password', name: 'admin_user_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, User $user): Response
    {
        if ($this->isCsrfTokenValid('reset-password-' . $user->getId(), $request->getPayload()->getString('_token'))) {
            $plainPassword = $this->userService->generateAndSetPassword($user);
            if ($this->sendInvitationEmail($user, $plainPassword)) {
                $this->addFlash('success', 'flash.user.password_reset');
            } else {
                $this->addFlash('warning', 'flash.mail.error');
            }
        }

        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/{id}/send-calendar-link', name: 'admin_user_send_calendar_link', methods: ['POST'])]
    public function sendCalendarLink(Request $request, User $user): Response
    {
        if (!$this->isCsrfTokenValid('send-calendar-link-' . $user->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('admin_user_index');
        }

        $token = $user->getCalendarToken();
        if ($token === null) {
            $this->addFlash('warning', 'flash.user.no_calendar_token');
            return $this->redirectToRoute('admin_user_index');
        }

        $calendarUrl  = $this->generateUrl('calendar_token_view', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $assemblyName = $user->getAssembly()?->getName() ?? 'Dienstplaner';
        $senderEmail  = $_ENV['MAILER_SENDER_EMAIL'] ?? 'noreply@example.com';
        $senderName   = $_ENV['MAILER_SENDER_NAME'] ?? 'Dienstplaner';

        $html = $this->renderView('email/calendar_link.html.twig', [
            'user'         => $user,
            'calendarUrl'  => $calendarUrl,
            'assemblyName' => $assemblyName,
        ]);

        $email = (new Email())
            ->from(new Address($senderEmail, $senderName))
            ->to($user->getEmail())
            ->subject('Dein persönlicher Kalenderlink – ' . $assemblyName)
            ->html($html);

        try {
            $this->mailer->send($email);
            $this->addFlash('success', 'flash.user.calendar_link_sent');
        } catch (\Throwable $e) {
            $this->logger->error('Kalenderlink-E-Mail konnte nicht gesendet werden', [
                'recipient' => $user->getEmail(),
                'error'     => $e->getMessage(),
            ]);
            $this->addFlash('warning', 'flash.mail.error');
        }

        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($currentUser);

        if ($assembly !== null && $user->getAssembly()?->getId() !== $assembly->getId()) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete-user-' . $user->getId(), $request->getPayload()->getString('_token'))) {
            $this->userService->deleteUser($user);
            $this->addFlash('success', 'flash.user.deleted');
        }
        return $this->redirectToRoute('admin_user_index');
    }

    /**
     * Sendet die Einladungs-E-Mail mit dem Klartext-Passwort an den neuen Benutzer.
     *
     * Gibt true zurück wenn die E-Mail erfolgreich versendet wurde, false bei einem Fehler.
     */
    private function sendInvitationEmail(User $user, string $plainPassword): bool
    {
        $assemblyName = $user->getAssembly()?->getName() ?? 'Dienstplaner';
        $loginUrl     = $this->generateUrl('security_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $senderEmail  = $_ENV['MAILER_SENDER_EMAIL'] ?? 'noreply@example.com';
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
}
