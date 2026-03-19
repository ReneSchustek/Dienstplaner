<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PersonRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;


/**
 * Person innerhalb einer Versammlung.
 *
 * Personen sind die planbaren Einheiten im System. Sie können optional
 * einem Benutzerkonto zugeordnet werden.
 */
#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ORM\Table(name: 'person')]
#[ORM\HasLifecycleCallbacks]
class Person
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: Assembly::class, inversedBy: 'persons')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Assembly $assembly;

    #[ORM\OneToMany(targetEntity: Assignment::class, mappedBy: 'person', cascade: ['remove'])]
    private Collection $assignments;

    #[ORM\OneToMany(targetEntity: Absence::class, mappedBy: 'person', cascade: ['remove'])]
    private Collection $absences;

    #[ORM\OneToMany(targetEntity: ExternalTask::class, mappedBy: 'person', cascade: ['remove'])]
    private Collection $externalTasks;

    #[ORM\ManyToMany(targetEntity: Task::class, inversedBy: 'persons')]
    #[ORM\JoinTable(name: 'person_task')]
    private Collection $tasks;

    public function __construct()
    {
        $this->assignments  = new ArrayCollection();
        $this->absences     = new ArrayCollection();
        $this->externalTasks = new ArrayCollection();
        $this->tasks        = new ArrayCollection();
        $this->createdAt    = new DateTimeImmutable();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
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

    /** @return Collection<int, Absence> */
    public function getAbsences(): Collection
    {
        return $this->absences;
    }

    /** @return Collection<int, ExternalTask> */
    public function getExternalTasks(): Collection
    {
        return $this->externalTasks;
    }

    /** @return Collection<int, Task> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function addTask(Task $task): static
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks->add($task);
        }
        return $this;
    }

    public function removeTask(Task $task): static
    {
        $this->tasks->removeElement($task);
        return $this;
    }

    public function hasTask(Task $task): bool
    {
        return $this->tasks->contains($task);
    }
}
