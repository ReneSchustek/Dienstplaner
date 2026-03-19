<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Passwort-Reset und Passwortänderung.
 *
 * Reset: öffentlich erreichbar (kein Login nötig).
 * Änderung: erfordert eingeloggten Benutzer.
 */
class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetService $passwordResetService,
    ) {}

    /**
     * Passwort-Reset anfordern (öffentlich).
     * POST: E-Mail-Adresse entgegennehmen und Einmal-Passwort versenden.
     */
    #[Route('/password-reset', name: 'password_reset_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $email = trim($request->request->getString('email'));
            // Immer gleiche Meldung anzeigen, unabhängig ob E-Mail bekannt ist
            $this->passwordResetService->requestReset($email);
            return $this->render('security/password_reset_sent.html.twig');
        }

        return $this->render('security/password_reset_request.html.twig');
    }

    /**
     * Passwort ändern (eingeloggter Benutzer).
     * Wird nach Login mit Einmal-Passwort erzwungen.
     */
    #[Route('/profile/change-password', name: 'profile_change_password', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('change-password', $request->request->getString('_token'))) {
                $this->addFlash('error', 'flash.csrf.invalid');
                return $this->redirectToRoute('profile_change_password');
            }

            $password = $request->request->getString('password');
            $confirm = $request->request->getString('password_confirm');

            if (strlen($password) < 8) {
                $this->addFlash('error', 'flash.password.too_short');
                return $this->redirectToRoute('profile_change_password');
            }

            if ($password !== $confirm) {
                $this->addFlash('error', 'flash.password.mismatch');
                return $this->redirectToRoute('profile_change_password');
            }

            $this->passwordResetService->changePassword($user, $password);
            $this->addFlash('success', 'flash.password.changed');
            return $this->redirectToRoute('dashboard');
        }

        return $this->render('security/change_password.html.twig');
    }
}
