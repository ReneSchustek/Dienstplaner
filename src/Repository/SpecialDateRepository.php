<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SpecialDate;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** Datenbankzugriff für besondere Termine. */
class SpecialDateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpecialDate::class);
    }

    public function findByAssembly(int $assemblyId): array
    {
        return $this->findBy(['assembly' => $assemblyId], ['startDate' => 'ASC']);
    }

    /** @return array{items: array, total: int, pages: int} */
    public function findFiltered(
        int $assemblyId,
        string $q = '',
        int $page = 1,
        int $limit = 25,
        string $sort = 'startDate',
        string $dir = 'ASC',
        int $year = 0,
    ): array {
        $qb = $this->createQueryBuilder('sd')
            ->where('sd.assembly = :assemblyId')
            ->setParameter('assemblyId', $assemblyId);

        if ($q !== '') {
            $qb->andWhere('sd.type LIKE :q OR sd.note LIKE :q')->setParameter('q', '%' . $q . '%');
        }

        if ($year > 0) {
            $from = new DateTimeImmutable(sprintf('%04d-01-01', $year));
            $to   = new DateTimeImmutable(sprintf('%04d-12-31', $year));
            $qb->andWhere('sd.startDate <= :filterTo')->setParameter('filterTo', $to)
               ->andWhere('sd.endDate >= :filterFrom')->setParameter('filterFrom', $from);
        }

        $allowedSort = ['startDate' => 'sd.startDate', 'endDate' => 'sd.endDate', 'type' => 'sd.type'];
        $sortField   = $allowedSort[$sort] ?? 'sd.startDate';
        $sortDir     = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $qb->orderBy($sortField, $sortDir);

        $total = (int) (clone $qb)->select('COUNT(sd.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    public function findByAssemblyAndPeriod(int $assemblyId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('sd')
            ->where('sd.assembly = :assemblyId')
            ->andWhere('sd.startDate <= :to')
            ->andWhere('sd.endDate >= :from')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('sd.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
