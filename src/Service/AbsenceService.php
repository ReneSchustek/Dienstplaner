<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use Doctrine\ORM\EntityManagerInterface;

/** Persistiert Abwesenheiten in der Datenbank. */
class AbsenceService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function save(Absence $absence): void
    {
        $this->entityManager->persist($absence);
        $this->entityManager->flush();
    }

    public function delete(Absence $absence): void
    {
        $this->entityManager->remove($absence);
        $this->entityManager->flush();
    }
}
