<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByAssembly(int $assemblyId): array
    {
        return $this->findBy(['assembly' => $assemblyId]);
    }

    /** @return array{items: array, total: int, pages: int} */
    public function findFiltered(int $assemblyId, string $q = '', int $page = 1, int $limit = 25): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.assembly = :assemblyId')
            ->setParameter('assemblyId', $assemblyId)
            ->orderBy('u.email', 'ASC');

        if ($q !== '') {
            $qb->andWhere('u.email LIKE :q OR u.name LIKE :q')->setParameter('q', '%' . $q . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    /** Sucht einen Benutzer anhand seines Kalender-Tokens. */
    public function findByCalendarToken(string $token): ?User
    {
        return $this->findOneBy(['calendarToken' => $token]);
    }

    /**
     * Alle Benutzer einer Versammlung mit verknüpfter Person,
     * indiziert nach person_id.
     *
     * @return array<int, \App\Entity\User>
     */
    public function findByAssemblyIndexedByPerson(int $assemblyId): array
    {
        $users = $this->createQueryBuilder('u')
            ->where('u.assembly = :assembly')
            ->andWhere('u.person IS NOT NULL')
            ->setParameter('assembly', $assemblyId)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($users as $user) {
            $map[$user->getPerson()->getId()] = $user;
        }
        return $map;
    }

    public function findByPerson(int $personId): ?User
    {
        return $this->findOneBy(['person' => $personId]);
    }

    /** Alle Benutzer einer Versammlung mit einer der angegebenen Rollen. */
    public function findByAssemblyAndRoles(int $assemblyId, array $roles): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.assembly = :assembly')
            ->andWhere('u.role IN (:roles)')
            ->setParameter('assembly', $assemblyId)
            ->setParameter('roles', $roles)
            ->getQuery()
            ->getResult();
    }
}
