<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Person;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** Datenbankzugriff für Personen. */
class PersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }

    /**
     * Gibt alle Personen einer Versammlung zurück, optional auf Abteilungen eingeschränkt.
     *
     * Mit $departmentIds werden nur Personen zurückgegeben, die mindestens eine
     * Aufgabe in einer der angegebenen Abteilungen haben. Ein leeres Array ergibt
     * eine leere Ergebnismenge (kein Fallback auf alle Personen).
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     */
    public function findByAssembly(int $assemblyId, ?array $departmentIds = null): array
    {
        if ($departmentIds !== null && empty($departmentIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('p')
            ->where('p.assembly = :assemblyId')
            ->setParameter('assemblyId', $assemblyId)
            ->orderBy('p.name', 'ASC');

        if ($departmentIds !== null) {
            $qb->join('p.tasks', 'pt')
               ->join('pt.department', 'ptd')
               ->andWhere('ptd.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds)
               ->groupBy('p.id');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Sucht Personen einer Versammlung nach Name, optional auf Abteilungen eingeschränkt.
     *
     * @param int[]|null $departmentIds null = kein Filter
     */
    public function searchByName(int $assemblyId, string $query, ?array $departmentIds = null): array
    {
        if ($departmentIds !== null && empty($departmentIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('p')
            ->where('p.assembly = :assemblyId')
            ->andWhere('p.name LIKE :query')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('p.name', 'ASC');

        if ($departmentIds !== null) {
            $qb->join('p.tasks', 'pt')
               ->join('pt.department', 'ptd')
               ->andWhere('ptd.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds)
               ->groupBy('p.id');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Gibt verfügbare Personen für ein Datum zurück, optional auf Abteilungen eingeschränkt.
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     */
    public function findAvailableForDate(int $assemblyId, \DateTimeImmutable $date, ?int $taskId, ?array $departmentIds = null): array
    {
        if ($departmentIds !== null && empty($departmentIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('p')
            ->where('p.assembly = :assemblyId')
            ->andWhere('NOT EXISTS (
                SELECT ab FROM App\Entity\Absence ab
                WHERE ab.person = p
                AND ab.startDate <= :date
                AND ab.endDate >= :date
            )')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('date', $date)
            ->orderBy('p.name', 'ASC');

        if ($taskId !== null) {
            $qb->join('p.tasks', 't')
               ->andWhere('t.id = :taskId')
               ->setParameter('taskId', $taskId);
        }

        if ($departmentIds !== null) {
            $qb->join('p.tasks', 'ptd_join')
               ->join('ptd_join.department', 'ptd')
               ->andWhere('ptd.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds)
               ->addGroupBy('p.id');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Gibt Person-Aufgaben-Paare zurück, optional auf Abteilungen eingeschränkt.
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     */
    public function findPersonTaskPairs(int $assemblyId, ?array $departmentIds = null): array
    {
        if ($departmentIds !== null && empty($departmentIds)) {
            return [];
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('p.id as personId, t.id as taskId')
            ->from('App\Entity\Person', 'p')
            ->join('p.tasks', 't')
            ->where('p.assembly = :assemblyId')
            ->setParameter('assemblyId', $assemblyId);

        if ($departmentIds !== null) {
            $qb->join('t.department', 'td')
               ->andWhere('td.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds);
        }

        $rows = $qb->getQuery()->getScalarResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['personId']][] = (int) $row['taskId'];
        }
        return $map;
    }

    /**
     * Gibt paginierte Personen einer Versammlung zurück, optional auf Abteilungen eingeschränkt.
     *
     * @param int[]|null $departmentIds null = kein Filter, [] = keine Ergebnisse
     * @return array{items: array, total: int, pages: int}
     */
    public function findFiltered(
        int $assemblyId,
        string $q = '',
        int $page = 1,
        int $limit = 25,
        string $sort = 'name',
        string $dir = 'ASC',
        ?array $departmentIds = null,
    ): array {
        if ($departmentIds !== null && empty($departmentIds)) {
            return ['items' => [], 'total' => 0, 'pages' => 1];
        }

        $qb = $this->createQueryBuilder('p')
            ->where('p.assembly = :assemblyId')
            ->setParameter('assemblyId', $assemblyId);

        if ($departmentIds !== null) {
            $qb->join('p.tasks', 'pt')
               ->join('pt.department', 'ptd')
               ->andWhere('ptd.id IN (:departmentIds)')
               ->setParameter('departmentIds', $departmentIds)
               ->groupBy('p.id');
        }

        if ($q !== '') {
            $qb->andWhere('p.name LIKE :q')->setParameter('q', '%' . $q . '%');
        }

        $allowedSort = ['name' => 'p.name', 'email' => 'p.email'];
        $sortField   = $allowedSort[$sort] ?? 'p.name';
        $sortDir     = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $qb->orderBy($sortField, $sortDir);

        $total = (int) (clone $qb)->select('COUNT(DISTINCT p.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    public function findByAssemblyAndTask(int $assemblyId, int $taskId): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.tasks', 't')
            ->where('p.assembly = :assemblyId')
            ->andWhere('t.id = :taskId')
            ->orderBy('p.name', 'ASC')
            ->setParameter('assemblyId', $assemblyId)
            ->setParameter('taskId', $taskId)
            ->getQuery()
            ->getResult();
    }
}
