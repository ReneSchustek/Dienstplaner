<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssemblyRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Versammlung – oberste Organisationseinheit im System.
 *
 * Alle Personen, Aufgaben und Planungsdaten gehören einer Versammlung an.
 */
#[ORM\Entity(repositoryClass: AssemblyRepository::class)]
#[ORM\Table(name: 'assembly')]
#[ORM\HasLifecycleCallbacks]
class Assembly
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'json')]
    private array $weekdays = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(targetEntity: Department::class, mappedBy: 'assembly', cascade: ['persist', 'remove'])]
    private Collection $departments;

    #[ORM\OneToMany(targetEntity: Person::class, mappedBy: 'assembly', cascade: ['persist', 'remove'])]
    private Collection $persons;

    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'assembly')]
    private Collection $users;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $planName = null;

    #[ORM\Column(type: 'string', length: 7)]
    private string $lineColor = '#1a56db';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $footerText = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $teamupCalendarUrl = null;

    /** Öffentlicher Token für Abwesenheitskalender der Versammlung. */
    #[ORM\Column(type: 'string', length: 64, nullable: true, unique: true)]
    private ?string $publicAbsenceToken = null;

    /** Öffentlicher Token für den vollständigen Kalender der Versammlung. */
    #[ORM\Column(type: 'string', length: 64, nullable: true, unique: true)]
    private ?string $publicCalendarToken = null;

    /**
     * 2FA-Richtlinie der Versammlung.
     * Werte: 'disabled', 'user_choice', 'totp', 'email'
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $twoFactorPolicy = 'user_choice';

    /** Anzahl der Tabellenzeilen pro Seite in der Paginierung. */
    #[ORM\Column(type: 'integer')]
    private int $pageSize = 10;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailInvitationSubject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mailInvitationBody = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailPasswordResetSubject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mailPasswordResetBody = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailCalendarLinkSubject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mailCalendarLinkBody = null;

    public function __construct()
    {
        $this->departments = new ArrayCollection();
        $this->persons = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    /** Setzt updatedAt auf den aktuellen Zeitpunkt (Doctrine-Lifecycle). */
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): static
    {
        $this->street = $street;
        return $this;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function setZip(?string $zip): static
    {
        $this->zip = $zip;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getWeekdays(): array
    {
        return $this->weekdays;
    }

    public function setWeekdays(array $weekdays): static
    {
        $this->weekdays = $weekdays;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, Department> */
    public function getDepartments(): Collection
    {
        return $this->departments;
    }

    /** @return Collection<int, Person> */
    public function getPersons(): Collection
    {
        return $this->persons;
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function getPlanName(): ?string
    {
        return $this->planName;
    }

    public function setPlanName(?string $planName): static
    {
        $this->planName = $planName;
        return $this;
    }

    public function getLineColor(): string
    {
        return $this->lineColor;
    }

    public function setLineColor(string $lineColor): static
    {
        $this->lineColor = $lineColor;
        return $this;
    }

    public function getFooterText(): ?string
    {
        return $this->footerText;
    }

    public function setFooterText(?string $footerText): static
    {
        $this->footerText = $footerText;
        return $this;
    }

    public function getTeamupCalendarUrl(): ?string
    {
        return $this->teamupCalendarUrl;
    }

    public function setTeamupCalendarUrl(?string $teamupCalendarUrl): static
    {
        $this->teamupCalendarUrl = $teamupCalendarUrl;
        return $this;
    }

    public function getTwoFactorPolicy(): string
    {
        return $this->twoFactorPolicy;
    }

    public function setTwoFactorPolicy(string $twoFactorPolicy): static
    {
        $this->twoFactorPolicy = $twoFactorPolicy;
        return $this;
    }

    public function getPublicAbsenceToken(): ?string
    {
        return $this->publicAbsenceToken;
    }

    public function setPublicAbsenceToken(?string $token): static
    {
        $this->publicAbsenceToken = $token;
        return $this;
    }

    public function getPublicCalendarToken(): ?string
    {
        return $this->publicCalendarToken;
    }

    public function setPublicCalendarToken(?string $token): static
    {
        $this->publicCalendarToken = $token;
        return $this;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /** Setzt die Seitengröße; Werte werden auf den Bereich 5–100 begrenzt. */
    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = max(5, min(100, $pageSize));
        return $this;
    }

    public function getMailInvitationSubject(): ?string
    {
        return $this->mailInvitationSubject;
    }

    public function setMailInvitationSubject(?string $mailInvitationSubject): static
    {
        $this->mailInvitationSubject = $mailInvitationSubject;
        return $this;
    }

    public function getMailInvitationBody(): ?string
    {
        return $this->mailInvitationBody;
    }

    public function setMailInvitationBody(?string $mailInvitationBody): static
    {
        $this->mailInvitationBody = $mailInvitationBody;
        return $this;
    }

    public function getMailPasswordResetSubject(): ?string
    {
        return $this->mailPasswordResetSubject;
    }

    public function setMailPasswordResetSubject(?string $mailPasswordResetSubject): static
    {
        $this->mailPasswordResetSubject = $mailPasswordResetSubject;
        return $this;
    }

    public function getMailPasswordResetBody(): ?string
    {
        return $this->mailPasswordResetBody;
    }

    public function setMailPasswordResetBody(?string $mailPasswordResetBody): static
    {
        $this->mailPasswordResetBody = $mailPasswordResetBody;
        return $this;
    }

    public function getMailCalendarLinkSubject(): ?string
    {
        return $this->mailCalendarLinkSubject;
    }

    public function setMailCalendarLinkSubject(?string $mailCalendarLinkSubject): static
    {
        $this->mailCalendarLinkSubject = $mailCalendarLinkSubject;
        return $this;
    }

    public function getMailCalendarLinkBody(): ?string
    {
        return $this->mailCalendarLinkBody;
    }

    public function setMailCalendarLinkBody(?string $mailCalendarLinkBody): static
    {
        $this->mailCalendarLinkBody = $mailCalendarLinkBody;
        return $this;
    }
}
