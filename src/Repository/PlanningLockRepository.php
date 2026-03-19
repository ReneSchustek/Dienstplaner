<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningLock;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PlanningLock> */
class PlanningLockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningLock::class);
    }

    /** Returns the active (non-expired) lock for a department, or null if none exists. */
    public function findActiveByDepartment(int $departmentId): ?PlanningLock
    {
        return $this->createQueryBuilder('l')
            ->where('l.department = :deptId')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('deptId', $departmentId)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns all active locks for departments belonging to the given assembly.
     *
     * @return PlanningLock[]
     */
    public function findActiveByAssembly(int $assemblyId): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.department', 'd')
            ->where('d.assembly = :assemblyId')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /** Deletes all expired locks. Returns the number of deleted rows. */
    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.expiresAt <= :now')
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
