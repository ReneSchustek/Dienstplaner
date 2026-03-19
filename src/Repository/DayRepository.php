<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Day;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** Datenbankzugriff für Planungstage. */
class DayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Day::class);
    }

    public function findByAssemblyAndPeriod(int $assemblyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.assembly = :assemblyId')
            ->andWhere('d.date >= :from')
            ->andWhere('d.date <= :to')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByAssemblyAndDate(int $assemblyId, \DateTimeImmutable $date): ?Day
    {
        return $this->findOneBy(['assembly' => $assemblyId, 'date' => $date]);
    }
}
