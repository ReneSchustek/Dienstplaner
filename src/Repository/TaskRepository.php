<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** Datenbankzugriff für Aufgaben. */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    public function findByDepartment(int $departmentId): array
    {
        return $this->findBy(['department' => $departmentId], ['name' => 'ASC']);
    }

    /**
     * Gibt alle Aufgaben einer Versammlung zurück, optional auf Abteilungen eingeschränkt.
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     */
    public function findByAssembly(int $assemblyId, ?array $departmentIds = null): array
    {
        if ($departmentIds !== null && empty($departmentIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('t')
            ->join('t.department', 'd')
            ->where('d.assembly = :assemblyId')
            ->setParameter('assemblyId', $assemblyId)
            ->orderBy('d.name', 'ASC')
            ->addOrderBy('t.name', 'ASC');

        if ($departmentIds !== null) {
            $qb->andWhere('d.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds);
        }

        return $qb->getQuery()->getResult();
    }
}
