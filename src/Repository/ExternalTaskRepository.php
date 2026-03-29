<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExternalTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** Datenbankzugriff für externe Aufgaben. */
class ExternalTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalTask::class);
    }

    public function findByAssembly(int $assemblyId): array
    {
        return $this->createQueryBuilder('et')
            ->join('et.person', 'p')
            ->where('p.assembly = :assemblyId')
            ->setParameter('assemblyId', $assemblyId)
            ->orderBy('et.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByPersonAndDay(int $personId, int $dayId): ?ExternalTask
    {
        return $this->findOneBy(['person' => $personId, 'day' => $dayId]);
    }

    /**
     * Gibt paginierte externe Aufgaben einer Versammlung zurück, optional auf Abteilungen eingeschränkt.
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     * @return array{items: array, total: int, pages: int}
     */
    public function findFiltered(
        int $assemblyId,
        string $q = '',
        int $page = 1,
        int $limit = 25,
        string $sort = 'date',
        string $dir = 'DESC',
        int $month = 0,
        int $year = 0,
        ?array $departmentIds = null,
    ): array {
        if ($departmentIds !== null && empty($departmentIds)) {
            return ['items' => [], 'total' => 0, 'pages' => 1];
        }

        $qb = $this->createQueryBuilder('et')
            ->join('et.person', 'p')
            ->join('et.day', 'd')
            ->where('p.assembly = :assemblyId')
            ->setParameter('assemblyId', $assemblyId);

        if ($departmentIds !== null) {
            $qb->join('p.tasks', 'pt')
               ->join('pt.department', 'ptd')
               ->andWhere('ptd.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds)
               ->groupBy('et.id');
        }

        if ($q !== '') {
            $qb->andWhere('p.firstName LIKE :q OR p.lastName LIKE :q')->setParameter('q', '%' . $q . '%');
        }

        if ($year > 0) {
            $from = $month > 0
                ? new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month))
                : new \DateTimeImmutable(sprintf('%04d-01-01', $year));
            $to = $month > 0
                ? new \DateTimeImmutable($from->format('Y-m-t'))
                : new \DateTimeImmutable(sprintf('%04d-12-31', $year));
            $qb->andWhere('d.date >= :filterFrom')->setParameter('filterFrom', $from)
               ->andWhere('d.date <= :filterTo')->setParameter('filterTo', $to);
        }

        $allowedSort = ['date' => 'd.date', 'person' => 'p.lastName'];
        $sortField   = $allowedSort[$sort] ?? 'd.date';
        $sortDir     = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';
        $qb->orderBy($sortField, $sortDir);

        $total = (int) (clone $qb)->select('COUNT(DISTINCT et.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    public function findByPersonAndMonth(int $personId, int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to   = new \DateTimeImmutable($from->format('Y-m-t'));

        return $this->findByPersonAndPeriod($personId, $from, $to);
    }

    public function findByPersonAndPeriod(int $personId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('et')
            ->join('et.day', 'd')
            ->where('et.person = :personId')
            ->andWhere('d.date >= :from')
            ->andWhere('d.date <= :to')
            ->setParameter('personId', $personId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByDays(array $dayIds): array
    {
        if (empty($dayIds)) {
            return [];
        }

        return $this->createQueryBuilder('et')
            ->where('et.day IN (:dayIds)')
            ->setParameter('dayIds', $dayIds)
            ->getQuery()
            ->getResult();
    }

    public function findByAssemblyAndPeriod(int $assemblyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('et')
            ->join('et.day', 'd')
            ->join('et.person', 'p')
            ->where('p.assembly = :assemblyId')
            ->andWhere('d.date >= :from')
            ->andWhere('d.date <= :to')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Alle ExternalTasks einer Versammlung, deren Beschreibung einem der angegebenen Namen entspricht. */
    public function findByDescriptions(array $descriptions, int $assemblyId): array
    {
        if (empty($descriptions)) {
            return [];
        }

        return $this->createQueryBuilder('et')
            ->join('et.person', 'p')
            ->where('et.description IN (:descriptions)')
            ->andWhere('p.assembly = :assemblyId')
            ->setParameter('descriptions', $descriptions)
            ->setParameter('assemblyId', $assemblyId)
            ->getQuery()
            ->getResult();
    }
}
