<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persistiert Personen in der Datenbank.
 */
class PersonService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function save(Person $person): void
    {
        $this->entityManager->persist($person);
        $this->entityManager->flush();
    }

    public function delete(Person $person): void
    {
        $this->entityManager->remove($person);
        $this->entityManager->flush();
    }
}
