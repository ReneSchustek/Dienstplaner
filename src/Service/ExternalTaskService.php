<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExternalTask;
use Doctrine\ORM\EntityManagerInterface;

/** Persistiert externe Aufgaben in der Datenbank. */
class ExternalTaskService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function save(ExternalTask $externalTask): void
    {
        $this->entityManager->persist($externalTask);
        $this->entityManager->flush();
    }

    public function delete(ExternalTask $externalTask): void
    {
        $this->entityManager->remove($externalTask);
        $this->entityManager->flush();
    }
}
