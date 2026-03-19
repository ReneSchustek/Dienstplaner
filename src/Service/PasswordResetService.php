<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Verwaltet den Passwort-Reset-Prozess.
 *
 * Generiert ein Einmal-Passwort, speichert es gehashed und sendet es per E-Mail.
 * Nach dem Login mit dem Einmal-Passwort wird der Benutzer zur Passwortänderung gezwungen.
 */
class PasswordResetService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly string $mailerSenderEmail,
        private readonly string $mailerSenderName,
    ) {}

    /**
     * Löst einen Passwort-Reset für die angegebene E-Mail-Adresse aus.
     *
     * Ist die E-Mail nicht bekannt, passiert nichts (keine Enumeration).
     * Gibt true zurück wenn eine E-Mail versendet wurde, false wenn nicht.
     */
    public function requestReset(string $email): bool
    {
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user === null) {
            return false;
        }

        $plainToken = $this->generateToken();
        $hashed = $this->passwordHasher->hashPassword($user, $plainToken);

        $user->setPasswordResetToken($hashed);
        $user->setForcePasswordChange(true);
        $this->entityManager->flush();

        $this->sendResetEmail($user, $plainToken);
        return true;
    }

    /**
     * Ändert das Passwort eines Benutzers und hebt die Änderungspflicht auf.
     */
    public function changePassword(User $user, string $newPlainPassword): void
    {
        $hashed = $this->passwordHasher->hashPassword($user, $newPlainPassword);
        $user->setPassword($hashed);
        $user->setForcePasswordChange(false);
        $user->setPasswordResetToken(null);
        $this->entityManager->flush();
    }

    /** Generiert ein zufälliges Einmal-Passwort (12 Zeichen). */
    private function generateToken(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $token = '';
        for ($i = 0; $i < 12; $i++) {
            $token .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $token;
    }

    /** Sendet das Einmal-Passwort per E-Mail. */
    private function sendResetEmail(User $user, string $plainToken): void
    {
        $email = (new Email())
            ->from(sprintf('%s <%s>', $this->mailerSenderName, $this->mailerSenderEmail))
            ->to($user->getEmail())
            ->subject('Dienstplaner – Passwort zurückgesetzt')
            ->html(sprintf(
                '<p>Ihr Einmal-Passwort lautet:</p><p><strong>%s</strong></p>'
                . '<p>Bitte melden Sie sich damit an und ändern Sie Ihr Passwort sofort.</p>',
                htmlspecialchars($plainToken)
            ));

        $this->mailer->send($email);
    }
}
