<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface as EmailTwoFactorInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface as TotpTwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
/**
 * Benutzerkonto für die Anmeldung am System.
 *
 * Implementiert Symfony Security, TOTP-2FA, E-Mail-2FA und Backup-Codes.
 * Ein Benutzer kann optional mit einem Personenprofil verknüpft sein.
 */
#[ORM\Table(name: 'user')]
#[ORM\UniqueConstraint(name: 'uq_user_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TotpTwoFactorInterface, EmailTwoFactorInterface, BackupCodeInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(type: 'string', length: 255)]
    private string $password;

    #[ORM\Column(type: 'string', length: 50)]
    private string $role = 'ROLE_USER';

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: Assembly::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Assembly $assembly = null;

    /** Abteilungen des Planers — nur bei ROLE_PLANER befüllt. */
    #[ORM\ManyToMany(targetEntity: Department::class)]
    #[ORM\JoinTable(name: 'user_departments')]
    private Collection $departments;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Person $person = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $theme = 'modern-classic';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(type: 'json')]
    private array $backupCodes = [];

    #[ORM\Column(type: 'boolean')]
    private bool $twoFactorRequired = false;

    /**
     * Persönlicher Kalender-Token für direkten Zugriff ohne Login.
     * Wird im Profil generiert und kann zurückgesetzt werden.
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true, unique: true)]
    private ?string $calendarToken = null;

    /** Einmal-Token für Passwort-Reset; wird nach Verwendung gelöscht. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $passwordResetToken = null;

    /** Erzwingt Passwortänderung beim nächsten Login. */
    #[ORM\Column(type: 'boolean')]
    private bool $forcePasswordChange = false;

    /**
     * Aktive 2FA-Methode: 'totp', 'email' oder null (deaktiviert).
     * Steuert, welche Implementierung bei der Anmeldung verwendet wird.
     */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $twoFactorMethod = null;

    /** Aktueller E-Mail-2FA-Code (vom scheb-Bundle gesetzt). */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $emailAuthCode = null;

    public function __construct()
    {
        $this->departments = new ArrayCollection();
        $this->createdAt   = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->name ?? $this->email;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getRoles(): array
    {
        return [$this->role, 'ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getAssembly(): ?Assembly
    {
        return $this->assembly;
    }

    public function setAssembly(?Assembly $assembly): static
    {
        $this->assembly = $assembly;
        return $this;
    }

    /** @return Collection<int, Department> */
    public function getDepartments(): Collection
    {
        return $this->departments;
    }

    public function addDepartment(Department $department): static
    {
        if (!$this->departments->contains($department)) {
            $this->departments->add($department);
        }
        return $this;
    }

    public function removeDepartment(Department $department): static
    {
        $this->departments->removeElement($department);
        return $this;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function setPerson(?Person $person): static
    {
        $this->person = $person;
        return $this;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): static
    {
        $this->theme = $theme;
        return $this;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if ($this->totpSecret === null) {
            return null;
        }
        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function isBackupCode(string $code): bool
    {
        return in_array($code, $this->backupCodes, true);
    }

    public function invalidateBackupCode(string $code): void
    {
        $this->backupCodes = array_values(array_filter($this->backupCodes, fn($c) => $c !== $code));
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): static
    {
        $this->totpSecret = $totpSecret;
        return $this;
    }

    public function getBackupCodes(): array
    {
        return $this->backupCodes;
    }

    public function setBackupCodes(array $backupCodes): static
    {
        $this->backupCodes = $backupCodes;
        return $this;
    }

    public function isTwoFactorRequired(): bool
    {
        return $this->twoFactorRequired;
    }

    public function setTwoFactorRequired(bool $twoFactorRequired): static
    {
        $this->twoFactorRequired = $twoFactorRequired;
        return $this;
    }

    public function getPasswordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function setPasswordResetToken(?string $passwordResetToken): static
    {
        $this->passwordResetToken = $passwordResetToken;
        return $this;
    }

    public function isForcePasswordChange(): bool
    {
        return $this->forcePasswordChange;
    }

    public function setForcePasswordChange(bool $forcePasswordChange): static
    {
        $this->forcePasswordChange = $forcePasswordChange;
        return $this;
    }

    /** TOTP takes precedence if both are configured. */
    public function isEmailAuthEnabled(): bool
    {
        return $this->twoFactorMethod === 'email';
    }

    public function getEmailAuthRecipient(): string
    {
        return $this->email;
    }

    public function getEmailAuthCode(): ?string
    {
        return $this->emailAuthCode;
    }

    public function setEmailAuthCode(?string $authCode): void
    {
        $this->emailAuthCode = $authCode;
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->twoFactorMethod === 'totp' && $this->totpSecret !== null;
    }

    public function getTwoFactorMethod(): ?string
    {
        return $this->twoFactorMethod;
    }

    public function setTwoFactorMethod(?string $twoFactorMethod): static
    {
        $this->twoFactorMethod = $twoFactorMethod;
        return $this;
    }

    public function getCalendarToken(): ?string
    {
        return $this->calendarToken;
    }

    public function setCalendarToken(?string $calendarToken): static
    {
        $this->calendarToken = $calendarToken;
        return $this;
    }
}
