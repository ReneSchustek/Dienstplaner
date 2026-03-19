<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use OTPHP\TOTP;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'profile_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/index.html.twig', ['user' => $user]);
    }

    #[Route('/update', name: 'profile_update', methods: ['POST'])]
    public function updateProfile(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile-update', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('profile_index');
        }

        /** @var User $user */
        $user = $this->getUser();

        $name = trim($request->request->getString('name'));
        $email = trim($request->request->getString('email'));

        if ($email === '') {
            $this->addFlash('error', 'flash.profile.email_required');
            return $this->redirectToRoute('profile_index');
        }

        $existing = $this->userRepository->findOneBy(['email' => $email]);
        if ($existing !== null && $existing->getId() !== $user->getId()) {
            $this->addFlash('error', 'profile.email_taken');
            return $this->redirectToRoute('profile_index');
        }

        $user->setName($name !== '' ? $name : null);
        $user->setEmail($email);
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.profile.saved');
        return $this->redirectToRoute('profile_index');
    }

    #[Route('/change-password', name: 'profile_change_password', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile-change-password', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('profile_index');
        }

        /** @var User $user */
        $user = $this->getUser();

        $currentPassword = $request->request->getString('current_password');
        $newPassword = $request->request->getString('new_password');
        $confirmPassword = $request->request->getString('confirm_password');

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            $this->addFlash('error', 'profile.current_password_wrong');
            return $this->redirectToRoute('profile_index');
        }

        if (strlen($newPassword) < 8) {
            $this->addFlash('error', 'flash.password.too_short');
            return $this->redirectToRoute('profile_index');
        }

        if ($newPassword !== $confirmPassword) {
            $this->addFlash('error', 'flash.password.mismatch');
            return $this->redirectToRoute('profile_index');
        }

        $hashed = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashed);
        $user->setForcePasswordChange(false);
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.profile.password_changed');
        return $this->redirectToRoute('profile_index');
    }

    #[Route('/theme', name: 'profile_theme', methods: ['POST'])]
    public function setTheme(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $theme = $request->request->getString('theme', 'modern-classic');

        $allowedThemes = ['modern-classic', 'midnight-library', 'paper-coffee'];
        if (in_array($theme, $allowedThemes, true)) {
            $user->setTheme($theme);
            $this->entityManager->flush();
        }

        $referer = $request->headers->get('referer', '');
        $host = $request->getSchemeAndHttpHost();
        if ($referer !== '' && str_starts_with($referer, $host)) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/2fa/method', name: 'profile_2fa_method', methods: ['POST'])]
    public function setTwoFactorMethod(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('2fa-method', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('profile_2fa');
        }

        /** @var User $user */
        $user = $this->getUser();
        $policy = $user->getAssembly()?->getTwoFactorPolicy() ?? 'user_choice';
        $method = $request->request->getString('method');

        $allowed = ['', 'totp', 'email'];
        if (!in_array($method, $allowed, true)) {
            $method = '';
        }

        // Policy 'disabled': 2FA cannot be activated
        if ($policy === 'disabled') {
            $method = '';
        }

        $user->setTwoFactorMethod($method === '' ? null : $method);
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.2fa.method_saved');
        return $this->redirectToRoute('profile_2fa');
    }

    #[Route('/2fa', name: 'profile_2fa', methods: ['GET'])]
    public function twoFactor(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $policy = $user->getAssembly()?->getTwoFactorPolicy() ?? 'user_choice';

        if ($policy === 'disabled' && $user->getTwoFactorMethod() !== null) {
            $user->setTwoFactorMethod(null);
            $this->entityManager->flush();
        }

        $qrCodeUri = null;
        $pendingSecret = $request->getSession()->get('pending_totp_secret');
        if ($pendingSecret !== null) {
            $totp = TOTP::createFromSecret($pendingSecret);
            $totp->setLabel($user->getEmail());
            $totp->setIssuer('Dienstplaner');
            $qrCode = new QrCode($totp->getProvisioningUri());
            $writer = new PngWriter();
            $result = $writer->write($qrCode);
            $qrCodeUri = $result->getDataUri();
        }

        $newBackupCodes = $request->getSession()->get('new_backup_codes');
        if ($newBackupCodes !== null) {
            $request->getSession()->remove('new_backup_codes');
        }

        return $this->render('profile/2fa.html.twig', [
            'user' => $user,
            'policy' => $policy,
            'pendingSecret' => $pendingSecret,
            'qrCodeUri' => $qrCodeUri,
            'newBackupCodes' => $newBackupCodes,
        ]);
    }

    #[Route('/2fa/setup', name: 'profile_2fa_setup', methods: ['POST'])]
    public function twoFactorSetup(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('2fa-setup', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('profile_2fa');
        }

        $totp = TOTP::generate();
        $request->getSession()->set('pending_totp_secret', $totp->getSecret());

        return $this->redirectToRoute('profile_2fa');
    }

    #[Route('/2fa/confirm', name: 'profile_2fa_confirm', methods: ['POST'])]
    public function twoFactorConfirm(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('2fa-confirm', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('profile_2fa');
        }

        /** @var User $user */
        $user = $this->getUser();
        $pendingSecret = $request->getSession()->get('pending_totp_secret');

        if ($pendingSecret === null) {
            return $this->redirectToRoute('profile_2fa');
        }

        $code = $request->request->getString('code');
        $totp = TOTP::createFromSecret($pendingSecret);
        $totp->setLabel($user->getEmail());
        $totp->setIssuer('Dienstplaner');

        if (!$totp->verify($code)) {
            $this->addFlash('error', 'flash.2fa.code_invalid');
            return $this->redirectToRoute('profile_2fa');
        }

        $backupCodes = $this->generateBackupCodes();
        $user->setTotpSecret($pendingSecret);
        $user->setBackupCodes($backupCodes);
        $this->entityManager->flush();

        $request->getSession()->remove('pending_totp_secret');
        $request->getSession()->set('new_backup_codes', $backupCodes);

        $this->addFlash('success', 'flash.2fa.enabled');
        return $this->redirectToRoute('profile_2fa');
    }

    #[Route('/2fa/disable', name: 'profile_2fa_disable', methods: ['POST'])]
    public function twoFactorDisable(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('2fa-disable', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('profile_2fa');
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('profile_2fa');
        }

        $code = $request->request->getString('code');
        $totp = TOTP::createFromSecret($user->getTotpSecret());
        $totp->setLabel($user->getEmail());
        $totp->setIssuer('Dienstplaner');

        if (!$totp->verify($code) && !$user->isBackupCode($code)) {
            $this->addFlash('error', 'flash.2fa.code_invalid');
            return $this->redirectToRoute('profile_2fa');
        }

        $user->setTotpSecret(null);
        $user->setBackupCodes([]);
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.2fa.disabled');
        return $this->redirectToRoute('profile_2fa');
    }

    #[Route('/2fa/backup-codes', name: 'profile_2fa_backup_codes', methods: ['POST'])]
    public function regenerateBackupCodes(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('2fa-backup-codes', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('profile_2fa');
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('profile_2fa');
        }

        $code = $request->request->getString('code');
        $totp = TOTP::createFromSecret($user->getTotpSecret());
        $totp->setLabel($user->getEmail());
        $totp->setIssuer('Dienstplaner');

        if (!$totp->verify($code) && !$user->isBackupCode($code)) {
            $this->addFlash('error', 'flash.2fa.code_invalid');
            return $this->redirectToRoute('profile_2fa');
        }

        $backupCodes = $this->generateBackupCodes();
        $user->setBackupCodes($backupCodes);
        $this->entityManager->flush();

        $request->getSession()->set('new_backup_codes', $backupCodes);

        $this->addFlash('success', 'flash.2fa.backup_codes_regenerated');
        return $this->redirectToRoute('profile_2fa');
    }

    /**
     * Generiert einen neuen persönlichen Kalender-Token und speichert ihn.
     * Ein vorhandener Token wird ersetzt.
     */
    #[Route('/calendar-token/generate', name: 'profile_calendar_token_generate', methods: ['POST'])]
    public function generateCalendarToken(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('calendar-token-generate', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('profile_2fa');
        }

        /** @var User $user */
        $user = $this->getUser();
        $token = bin2hex(random_bytes(32));
        $user->setCalendarToken($token);
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.calendar_token.generated');
        return $this->redirectToRoute('profile_2fa');
    }

    /**
     * Widerruft den persönlichen Kalender-Token.
     * Der Link wird damit ungültig.
     */
    #[Route('/calendar-token/revoke', name: 'profile_calendar_token_revoke', methods: ['POST'])]
    public function revokeCalendarToken(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('calendar-token-revoke', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('profile_2fa');
        }

        /** @var User $user */
        $user = $this->getUser();
        $user->setCalendarToken(null);
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.calendar_token.revoked');
        return $this->redirectToRoute('profile_2fa');
    }

    #[Route('/delete-account', name: 'profile_delete_account', methods: ['POST'])]
    public function deleteAccount(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile-delete-account', $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('profile_index');
        }

        /** @var User $user */
        $user = $this->getUser();

        $deletedName  = $user->getDisplayName();
        $deletedEmail = $user->getEmail();
        $assembly     = $user->getAssembly();
        $assemblyName = $assembly?->getName() ?? '–';
        $deletedAt    = (new \DateTimeImmutable())->format('d.m.Y H:i');

        $notifyUsers = $assembly !== null
            ? $this->userRepository->findByAssemblyAndRoles(
                $assembly->getId(),
                ['ROLE_ASSEMBLY_ADMIN', 'ROLE_PLANER'],
            )
            : [];

        // Löschen: Person bleibt erhalten, nur User-Account entfernen
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $request->getSession()->invalidate();
        $this->addFlash('success', 'flash.account.deleted');

        $this->sendAccountDeletedNotifications(
            $notifyUsers,
            $deletedName,
            $deletedEmail,
            $assemblyName,
            $deletedAt,
        );

        return $this->redirectToRoute('security_login');
    }

    private function sendAccountDeletedNotifications(
        array $recipients,
        string $deletedName,
        string $deletedEmail,
        string $assemblyName,
        string $deletedAt,
    ): void {
        $senderEmail = $_ENV['MAILER_SENDER_EMAIL'] ?? 'noreply@dienstplaner.local';
        $senderName  = $_ENV['MAILER_SENDER_NAME'] ?? 'Dienstplaner';

        $html = $this->renderView('email/account_deleted.html.twig', [
            'deletedName'  => $deletedName,
            'deletedEmail' => $deletedEmail,
            'assemblyName' => $assemblyName,
            'deletedAt'    => $deletedAt,
        ]);

        foreach ($recipients as $recipient) {
            if ($recipient->getEmail() === $deletedEmail) {
                continue;
            }

            try {
                $email = (new Email())
                    ->from(new Address($senderEmail, $senderName))
                    ->to($recipient->getEmail())
                    ->subject('[Dienstplaner] Konto gelöscht – ' . $assemblyName)
                    ->html($html);

                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Account-deletion notification failed', [
                    'recipient' => $recipient->getEmail(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    private function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }
}
