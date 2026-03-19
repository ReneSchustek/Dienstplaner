<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExternalTaskRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Externe Aufgabe einer Person an einem Planungstag.
 *
 * Externe Aufgaben blockieren die Person für reguläre Zuteilungen
 * (z. B. Dienst in einer anderen Abteilung).
 */
#[ORM\Entity(repositoryClass: ExternalTaskRepository::class)]
#[ORM\Table(name: 'external_task')]
#[ORM\UniqueConstraint(name: 'uq_external_task_person_day', columns: ['person_id', 'day_id'])]
class ExternalTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Person::class, inversedBy: 'externalTasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $person;

    #[ORM\ManyToOne(targetEntity: Day::class, inversedBy: 'externalTasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Day $day;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPerson(): Person
    {
        return $this->person;
    }

    public function setPerson(Person $person): static
    {
        $this->person = $person;
        return $this;
    }

    public function getDay(): Day
    {
        return $this->day;
    }

    public function setDay(Day $day): static
    {
        $this->day = $day;
        return $this;
    }
}
