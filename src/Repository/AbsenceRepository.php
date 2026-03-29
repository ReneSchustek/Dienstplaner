<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Absence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** Datenbankzugriff für Abwesenheiten. */
class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    public function findByPerson(int $personId): array
    {
        return $this->findBy(['person' => $personId], ['startDate' => 'DESC']);
    }

    /** Gibt Abwesenheiten einer Person zurück, die den angegebenen Monat überschneiden. */
    public function findByPersonAndMonth(int $personId, int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to   = new \DateTimeImmutable($from->format('Y-m-t'));

        return $this->createQueryBuilder('a')
            ->where('a.person = :personId')
            ->andWhere('a.startDate <= :to')
            ->andWhere('a.endDate >= :from')
            ->setParameter('personId', $personId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAbsencesForPersonOnDate(int $personId, \DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.person = :personId')
            ->andWhere('a.startDate <= :date')
            ->andWhere('a.endDate >= :date')
            ->setParameter('personId', $personId)
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();
    }

    /**
     * Gibt Abwesenheiten einer Versammlung für einen Zeitraum zurück, optional auf Abteilungen eingeschränkt.
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     */
    public function findByAssemblyAndPeriod(int $assemblyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?array $departmentIds = null): array
    {
        if ($departmentIds !== null && empty($departmentIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->join('a.person', 'p')
            ->where('p.assembly = :assemblyId')
            ->andWhere('a.startDate <= :to')
            ->andWhere('a.endDate >= :from')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startDate', 'ASC');

        if ($departmentIds !== null) {
            $qb->join('p.tasks', 'apt')
               ->join('apt.department', 'aptd')
               ->andWhere('aptd.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds)
               ->groupBy('a.id');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Gibt paginierte Abwesenheiten einer Versammlung zurück, optional auf Abteilungen eingeschränkt.
     *
     * Abwesenheiten werden über die Person-Aufgaben-Zuordnung auf Abteilungen gefiltert:
     * Nur Personen deren Aufgaben in einer der angegebenen Abteilungen liegen werden berücksichtigt.
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     * @return array{items: array, total: int, pages: int}
     */
    public function findFiltered(
        int $assemblyId,
        string $q = '',
        int $page = 1,
        int $limit = 25,
        string $sort = 'startDate',
        string $dir = 'ASC',
        int $month = 0,
        int $year = 0,
        ?array $departmentIds = null,
    ): array {
        if ($departmentIds !== null && empty($departmentIds)) {
            return ['items' => [], 'total' => 0, 'pages' => 1];
        }

        $qb = $this->createQueryBuilder('a')
            ->join('a.person', 'p')
            ->where('p.assembly = :assemblyId')
            ->setParameter('assemblyId', $assemblyId);

        if ($departmentIds !== null) {
            $qb->join('p.tasks', 'pt')
               ->join('pt.department', 'ptd')
               ->andWhere('ptd.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds)
               ->groupBy('a.id');
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
            $qb->andWhere('a.startDate <= :filterTo')->setParameter('filterTo', $to)
               ->andWhere('a.endDate >= :filterFrom')->setParameter('filterFrom', $from);
        }

        $allowedSort = ['startDate' => 'a.startDate', 'endDate' => 'a.endDate', 'person' => 'p.lastName'];
        $sortField   = $allowedSort[$sort] ?? 'a.startDate';
        $sortDir     = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $qb->orderBy($sortField, $sortDir);

        $total = (int) (clone $qb)->select('COUNT(DISTINCT a.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    /**
     * Gibt Abwesenheiten für Personen zurück, deren Aufgaben in den angegebenen Abteilungen liegen.
     *
     * Ersetzt findByDepartmentAndPeriod für den Planer-Scope mit mehreren Abteilungen.
     *
     * @param int[] $departmentIds
     */
    public function findByDepartmentsAndPeriod(array $departmentIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (empty($departmentIds)) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->join('a.person', 'p')
            ->join('p.tasks', 'pt')
            ->join('pt.department', 'd')
            ->where('d.id IN (:departmentIds)')
            ->andWhere('a.startDate <= :to')
            ->andWhere('a.endDate >= :from')
            ->setParameter('departmentIds', $departmentIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startDate', 'ASC')
            ->groupBy('a.id')
            ->getQuery()
            ->getResult();
    }
}
