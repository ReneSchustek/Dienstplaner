<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningLockRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Editing lock for a planning department.
 *
 * Prevents two planners from editing the same department simultaneously.
 * Expires automatically after a configurable duration if no heartbeat is received.
 */
#[ORM\Entity(repositoryClass: PlanningLockRepository::class)]
#[ORM\Table(name: 'planning_lock')]
#[ORM\UniqueConstraint(name: 'uq_planning_lock_department', columns: ['department_id'])]
class PlanningLock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Department $department;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $lockedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    public function __construct(Department $department, User $user, DateTimeImmutable $expiresAt)
    {
        $this->department = $department;
        $this->user       = $user;
        $this->lockedAt   = new DateTimeImmutable();
        $this->expiresAt  = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDepartment(): Department
    {
        return $this->department;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLockedAt(): DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }
}
