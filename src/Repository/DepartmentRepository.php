<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Department;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** Datenbankzugriff für Abteilungen. */
class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    public function findByAssembly(int $assemblyId): array
    {
        return $this->findBy(['assembly' => $assemblyId], ['name' => 'ASC']);
    }
}
