<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssignmentRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssignmentRepository::class)]
/**
 * Zuteilung einer Person zu einer Aufgabe an einem Planungstag.
 *
 * Pro Tag kann eine Person nur eine Aufgabe haben, und eine Aufgabe
 * kann nur einer Person zugeteilt sein (Unique Constraints).
 */
#[ORM\Table(name: 'assignment')]
#[ORM\UniqueConstraint(name: 'uq_assignment_task_day', columns: ['task_id', 'day_id'])]
#[ORM\UniqueConstraint(name: 'uq_assignment_person_day', columns: ['person_id', 'day_id'])]
class Assignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Person::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $person;

    #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\ManyToOne(targetEntity: Day::class, inversedBy: 'assignments')]
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

    public function getTask(): Task
    {
        return $this->task;
    }

    public function setTask(Task $task): static
    {
        $this->task = $task;
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
