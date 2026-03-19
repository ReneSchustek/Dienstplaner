<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Person;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Erstellt und aktualisiert Benutzerkonten.
 *
 * Passwörter werden über den Symfony PasswordHasher gehasht.
 */
class UserService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /** Hasht das Passwort und speichert den Benutzer in der Datenbank. */
    public function createUser(User $user, string $plainPassword): void
    {
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    /**
     * Legt eine eingeladenen Benutzer an.
     *
     * Erstellt automatisch ein verknüpftes Personenprofil, wenn noch keins vorhanden ist.
     * Gibt das generierte Klartextpasswort zurück – einmalig, danach nicht mehr abrufbar.
     */
    public function inviteUser(User $user): string
    {
        $this->ensurePersonLinked($user);
        $plainPassword = bin2hex(random_bytes(8));
        $hashed = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashed);
        $user->setForcePasswordChange(true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $plainPassword;
    }

    /**
     * Generiert ein zufälliges Passwort, setzt es und aktiviert die Änderungspflicht.
     *
     * Gibt das Klartext-Passwort zurück – einmalig, danach nicht mehr abrufbar.
     */
    public function generateAndSetPassword(User $user): string
    {
        $plainPassword = bin2hex(random_bytes(8));
        $hashed = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashed);
        $user->setForcePasswordChange(true);
        $this->entityManager->flush();

        return $plainPassword;
    }

    /**
     * Aktualisiert Benutzerdaten.
     *
     * Hasht das neue Passwort nur, wenn ein nicht-leeres Klartextpasswort übergeben wird.
     * Synchronisiert den Namen des verknüpften Personenprofils, wenn vorhanden.
     */
    public function updateUser(User $user, ?string $plainPassword): void
    {
        if ($plainPassword !== null && $plainPassword !== '') {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);
        }
        $this->syncPersonName($user);
        $this->entityManager->flush();
    }

    /** Löscht den Benutzer aus der Datenbank. */
    public function deleteUser(User $user): void
    {
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    private function ensurePersonLinked(User $user): void
    {
        if ($user->getPerson() !== null || $user->getAssembly() === null) {
            return;
        }

        $person = new Person();
        $person->setName($user->getName() ?? $user->getEmail());
        $person->setAssembly($user->getAssembly());
        $person->setEmail($user->getEmail());

        $this->entityManager->persist($person);
        $user->setPerson($person);
    }

    private function syncPersonName(User $user): void
    {
        $person = $user->getPerson();
        if ($person === null || $user->getName() === null) {
            return;
        }
        $person->setName($user->getName());
    }
}
