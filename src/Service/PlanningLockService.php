<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assembly;
use App\Entity\Department;
use App\Entity\PlanningLock;
use App\Entity\User;
use App\Repository\PlanningLockRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages editing locks for planning departments.
 *
 * A lock prevents two planners from editing the same department simultaneously.
 * Locks expire after LOCK_MINUTES minutes without a heartbeat and are cleaned
 * up lazily on the next acquire call.
 */
class PlanningLockService
{
    private const LOCK_MINUTES = 10;

    public function __construct(
        private readonly PlanningLockRepository $lockRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Tries to acquire an editing lock for the given department.
     *
     * Returns ['acquired' => true] on success.
     * Returns ['acquired' => false, 'lockedBy' => name] if a foreign lock is active.
     *
     * @return array{acquired: bool, lockedBy?: string}
     */
    public function acquire(Department $department, User $user): array
    {
        $this->lockRepository->deleteExpired();

        $existing = $this->lockRepository->findActiveByDepartment($department->getId());

        if ($existing !== null && $existing->getUser()->getId() !== $user->getId()) {
            return [
                'acquired' => false,
                'lockedBy' => $existing->getUser()->getDisplayName(),
            ];
        }

        if ($existing !== null) {
            // Extend own lock (heartbeat).
            $existing->setExpiresAt($this->newExpiresAt());
            $this->em->flush();
            return ['acquired' => true];
        }

        $lock = new PlanningLock($department, $user, $this->newExpiresAt());
        $this->em->persist($lock);
        $this->em->flush();

        return ['acquired' => true];
    }

    /** Releases a department lock if it belongs to the given user. */
    public function release(Department $department, User $user): void
    {
        $lock = $this->lockRepository->findActiveByDepartment($department->getId());

        if ($lock !== null && $lock->getUser()->getId() === $user->getId()) {
            $this->em->remove($lock);
            $this->em->flush();
        }
    }

    /** Releases all active locks of the given user within an assembly (e.g. on page leave). */
    public function releaseAllByUser(User $user, Assembly $assembly): void
    {
        $locks = $this->lockRepository->findActiveByAssembly($assembly->getId());

        foreach ($locks as $lock) {
            if ($lock->getUser()->getId() === $user->getId()) {
                $this->em->remove($lock);
            }
        }

        $this->em->flush();
    }

    /**
     * Returns lock status for all departments in an assembly.
     *
     * @return array<int, array{lockedBy: string, expiresAt: int}>
     */
    public function getStatusForAssembly(Assembly $assembly): array
    {
        $locks  = $this->lockRepository->findActiveByAssembly($assembly->getId());
        $status = [];

        foreach ($locks as $lock) {
            $status[$lock->getDepartment()->getId()] = [
                'lockedBy'  => $lock->getUser()->getDisplayName(),
                'expiresAt' => $lock->getExpiresAt()->getTimestamp(),
            ];
        }

        return $status;
    }

    /** Returns true if a department is actively locked by a different user. */
    public function isLockedByOther(Department $department, User $user): bool
    {
        $lock = $this->lockRepository->findActiveByDepartment($department->getId());
        return $lock !== null && $lock->getUser()->getId() !== $user->getId();
    }

    private function newExpiresAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('+' . self::LOCK_MINUTES . ' minutes');
    }
}
