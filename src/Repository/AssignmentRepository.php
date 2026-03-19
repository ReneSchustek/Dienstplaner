<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** Datenbankzugriff für Zuteilungen. */
class AssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assignment::class);
    }

    public function findByPersonAndDay(int $personId, int $dayId): ?Assignment
    {
        return $this->findOneBy(['person' => $personId, 'day' => $dayId]);
    }

    public function findByTaskAndDay(int $taskId, int $dayId): ?Assignment
    {
        return $this->findOneBy(['task' => $taskId, 'day' => $dayId]);
    }

    public function findByPersonAndPeriod(int $personId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.day', 'd')
            ->where('a.person = :personId')
            ->setParameter('personId', $personId)
            ->orderBy('d.date', 'DESC');

        if ($from !== null) {
            $qb->andWhere('d.date >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('d.date <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByTaskGroupedByPerson(int $taskId): array
    {
        return $this->createQueryBuilder('a')
            ->select('IDENTITY(a.person) as personId, COUNT(a.id) as cnt')
            ->where('a.task = :taskId')
            ->setParameter('taskId', $taskId)
            ->groupBy('a.person')
            ->getQuery()
            ->getResult();
    }

    public function findByTaskAndPeriod(int $taskId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.day', 'd')
            ->where('a.task = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('d.date', 'DESC');

        if ($from !== null) {
            $qb->andWhere('d.date >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('d.date <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Gibt gefilterte Zuteilungen einer Versammlung zurück, optional auf Abteilungen eingeschränkt.
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     */
    public function findByAssemblyAndPeriodFiltered(
        int $assemblyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?int $personId,
        ?int $taskId,
        ?int $deptId,
        ?array $departmentIds = null
    ): array {
        if ($departmentIds !== null && empty($departmentIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->join('a.day', 'd')
            ->join('a.person', 'p')
            ->join('a.task', 't')
            ->join('t.department', 'dept')
            ->where('p.assembly = :assemblyId')
            ->andWhere('d.date >= :from')
            ->andWhere('d.date <= :to')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('d.date', 'ASC');

        if ($personId !== null) {
            $qb->andWhere('p.id = :personId')->setParameter('personId', $personId);
        }
        if ($taskId !== null) {
            $qb->andWhere('t.id = :taskId')->setParameter('taskId', $taskId);
        }
        if ($deptId !== null) {
            $qb->andWhere('dept.id = :deptId')->setParameter('deptId', $deptId);
        }
        if ($departmentIds !== null) {
            $qb->andWhere('dept.id IN (:departmentIds)')->setParameter('departmentIds', $departmentIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Zählt Zuteilungen pro Person und Abteilung für einen Zeitraum, optional auf Abteilungen eingeschränkt.
     *
     * Gibt ein Array von Zeilen zurück:
     * [['personId' => int, 'deptId' => int, 'cnt' => int], ...]
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     */
    public function countByPersonAndDepartment(
        int $assemblyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $departmentIds = null
    ): array {
        if ($departmentIds !== null && empty($departmentIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->select(
                'IDENTITY(a.person) as personId',
                'IDENTITY(t.department) as deptId',
                'COUNT(a.id) as cnt'
            )
            ->join('a.day', 'd')
            ->join('a.person', 'p')
            ->join('a.task', 't')
            ->where('p.assembly = :assemblyId')
            ->andWhere('d.date >= :from')
            ->andWhere('d.date <= :to')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('a.person', 't.department')
            ->orderBy('a.person', 'ASC');

        if ($departmentIds !== null) {
            $qb->join('t.department', 'tdept')
               ->andWhere('tdept.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Zählt Zuteilungen pro Person und Aufgabe für ein Jahr (optional: Monat), optional auf Abteilungen eingeschränkt.
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     */
    public function countByPersonAndTask(int $assemblyId, int $year, ?int $month, ?array $departmentIds = null): array
    {
        if ($departmentIds !== null && empty($departmentIds)) {
            return [];
        }

        $from = $month !== null
            ? new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month))
            : new \DateTimeImmutable(sprintf('%04d-01-01', $year));
        $to = $month !== null
            ? $from->modify('last day of this month')
            : new \DateTimeImmutable(sprintf('%04d-12-31', $year));

        $qb = $this->createQueryBuilder('a')
            ->select(
                'IDENTITY(a.person) as personId',
                'IDENTITY(a.task) as taskId',
                'COUNT(a.id) as cnt'
            )
            ->join('a.day', 'd')
            ->join('a.person', 'p')
            ->where('p.assembly = :assemblyId')
            ->andWhere('d.date >= :from')
            ->andWhere('d.date <= :to')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('a.person', 'a.task');

        if ($departmentIds !== null) {
            $qb->join('a.task', 'atask')
               ->join('atask.department', 'adept')
               ->andWhere('adept.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds);
        }

        return $qb->getQuery()->getResult();
    }

    public function findByDays(array $dayIds): array
    {
        if (empty($dayIds)) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->where('a.day IN (:dayIds)')
            ->setParameter('dayIds', $dayIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Alle Zuteilungen einer Versammlung für einen Monat,
     * gruppiert nach Person (person_id → Assignment[]).
     *
     * @return array<int, Assignment[]>
     */
    public function findByAssemblyAndPeriod(int $assemblyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.day', 'd')
            ->join('a.person', 'p')
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

    public function findByAssemblyAndMonthGroupedByPerson(int $assemblyId, int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to   = $from->modify('last day of this month');

        $assignments = $this->createQueryBuilder('a')
            ->join('a.day', 'd')
            ->join('a.person', 'p')
            ->where('p.assembly = :assemblyId')
            ->andWhere('d.date >= :from')
            ->andWhere('d.date <= :to')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($assignments as $assignment) {
            $personId = $assignment->getPerson()->getId();
            $grouped[$personId][] = $assignment;
        }

        return $grouped;
    }
}
