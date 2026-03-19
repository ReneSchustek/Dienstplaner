<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DayRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Planungstag einer Versammlung.
 *
 * Ein Day repräsentiert einen einzelnen Wochentag innerhalb eines
 * Planungsmonats. Jeder Tag ist pro Versammlung eindeutig (date + assembly).
 */
#[ORM\Entity(repositoryClass: DayRepository::class)]
#[ORM\Table(name: 'day')]
#[ORM\UniqueConstraint(name: 'uq_day_assembly_date', columns: ['assembly_id', 'date'])]
class Day
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $date;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Assembly::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Assembly $assembly;

    #[ORM\OneToMany(targetEntity: Assignment::class, mappedBy: 'day', cascade: ['remove'])]
    private Collection $assignments;

    #[ORM\OneToMany(targetEntity: ExternalTask::class, mappedBy: 'day', cascade: ['remove'])]
    private Collection $externalTasks;

    public function __construct()
    {
        $this->assignments = new ArrayCollection();
        $this->externalTasks = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(DateTimeImmutable $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAssembly(): Assembly
    {
        return $this->assembly;
    }

    public function setAssembly(Assembly $assembly): static
    {
        $this->assembly = $assembly;
        return $this;
    }

    /** @return Collection<int, Assignment> */
    public function getAssignments(): Collection
    {
        return $this->assignments;
    }

    /** @return Collection<int, ExternalTask> */
    public function getExternalTasks(): Collection
    {
        return $this->externalTasks;
    }
}
