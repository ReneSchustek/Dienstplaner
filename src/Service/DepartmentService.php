<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Department;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persistiert Abteilungen in der Datenbank.
 */
class DepartmentService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function save(Department $department): void
    {
        $this->entityManager->persist($department);
        $this->entityManager->flush();
    }

    public function delete(Department $department): void
    {
        $this->entityManager->remove($department);
        $this->entityManager->flush();
    }
}
