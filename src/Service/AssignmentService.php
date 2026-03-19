<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\Day;
use App\Entity\Person;
use App\Entity\Task;
use App\Repository\AbsenceRepository;
use App\Repository\AssignmentRepository;
use App\Repository\ExternalTaskRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Erstellt und entfernt Aufgabenzuteilungen.
 *
 * Prüft vor jeder Zuteilung, ob Person und Aufgabe am jeweiligen
 * Tag noch verfügbar sind (keine Abwesenheit, keine externe Aufgabe,
 * keine bestehende Zuteilung).
 */
class AssignmentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly AbsenceRepository $absenceRepository,
        private readonly ExternalTaskRepository $externalTaskRepository,
    ) {}

    /**
     * Assigns a person to a task on a given day.
     *
     * When $force is true, absence and external-task conflicts are bypassed.
     * Double-assignment on the same task slot is always rejected.
     *
     * @throws \DomainException code=1 when person is unavailable (can be forced), code=0 for task conflicts
     */
    public function assign(Person $person, Task $task, Day $day, bool $force = false): Assignment
    {
        if (!$force) {
            $this->assertPersonIsAvailable($person, $day);
        }
        $this->assertTaskIsAvailable($task, $day);

        $assignment = new Assignment();
        $assignment->setPerson($person);
        $assignment->setTask($task);
        $assignment->setDay($day);

        $this->entityManager->persist($assignment);
        $this->entityManager->flush();

        return $assignment;
    }

    /** Gibt die Zuteilung für eine Aufgabe an einem Tag zurück, oder null. */
    public function findByTaskAndDay(Task $task, Day $day): ?Assignment
    {
        return $this->assignmentRepository->findByTaskAndDay($task->getId(), $day->getId());
    }

    public function removeAssignment(Assignment $assignment): void
    {
        $this->entityManager->remove($assignment);
        $this->entityManager->flush();
    }

    /**
     * Prüft ob die Person am gegebenen Tag verfügbar ist.
     *
     * Wirft eine Exception bei Abwesenheit, externer Aufgabe oder bestehender Zuteilung.
     *
     * @throws \DomainException
     */
    private function assertPersonIsAvailable(Person $person, Day $day): void
    {
        $absences = $this->absenceRepository->findAbsencesForPersonOnDate(
            $person->getId(),
            $day->getDate()
        );

        if (!empty($absences)) {
            throw new \DomainException('Die Person ist an diesem Tag abwesend.', 1);
        }

        $externalTask = $this->externalTaskRepository->findByPersonAndDay(
            $person->getId(),
            $day->getId()
        );

        if ($externalTask !== null) {
            throw new \DomainException('Die Person hat an diesem Tag eine externe Aufgabe.', 1);
        }

        $existingAssignment = $this->assignmentRepository->findByPersonAndDay(
            $person->getId(),
            $day->getId()
        );

        if ($existingAssignment !== null) {
            throw new \DomainException('Die Person hat an diesem Tag bereits eine Aufgabe.', 1);
        }
    }

    /**
     * Prüft ob die Aufgabe am gegebenen Tag noch nicht vergeben ist.
     *
     * @throws \DomainException
     */
    private function assertTaskIsAvailable(Task $task, Day $day): void
    {
        $existingAssignment = $this->assignmentRepository->findByTaskAndDay(
            $task->getId(),
            $day->getId()
        );

        if ($existingAssignment !== null) {
            throw new \DomainException('Die Aufgabe ist an diesem Tag bereits vergeben.');
        }
    }
}
