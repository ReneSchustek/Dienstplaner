<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assembly;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** Datenbankzugriff für Versammlungen. */
class AssemblyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assembly::class);
    }

    public function findByPublicAbsenceToken(string $token): ?Assembly
    {
        return $this->findOneBy(['publicAbsenceToken' => $token]);
    }

    public function findByPublicCalendarToken(string $token): ?Assembly
    {
        return $this->findOneBy(['publicCalendarToken' => $token]);
    }
}
