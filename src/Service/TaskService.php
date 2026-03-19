<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persistiert Aufgaben in der Datenbank.
 */
class TaskService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function save(Task $task): void
    {
        $this->entityManager->persist($task);
        $this->entityManager->flush();
    }

    public function delete(Task $task): void
    {
        $this->entityManager->remove($task);
        $this->entityManager->flush();
    }
}
