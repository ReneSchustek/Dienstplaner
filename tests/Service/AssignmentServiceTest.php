<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assignment;
use App\Entity\Day;
use App\Entity\Person;
use App\Entity\Task;
use App\Repository\AbsenceRepository;
use App\Repository\AssignmentRepository;
use App\Repository\ExternalTaskRepository;
use App\Service\AssignmentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AssignmentServiceTest extends TestCase
{
    private AssignmentService $service;
    private MockObject&AssignmentRepository $assignmentRepository;
    private MockObject&AbsenceRepository $absenceRepository;
    private MockObject&ExternalTaskRepository $externalTaskRepository;
    private MockObject&EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->assignmentRepository = $this->createMock(AssignmentRepository::class);
        $this->absenceRepository = $this->createMock(AbsenceRepository::class);
        $this->externalTaskRepository = $this->createMock(ExternalTaskRepository::class);

        $this->service = new AssignmentService(
            $this->entityManager,
            $this->assignmentRepository,
            $this->absenceRepository,
            $this->externalTaskRepository,
        );
    }

    public function testAssignSucceeds(): void
    {
        $person = $this->createPersonWithId(1);
        $task = $this->createTaskWithId(1);
        $day = $this->createDayWithId(1);

        $this->absenceRepository->method('findAbsencesForPersonOnDate')->willReturn([]);
        $this->externalTaskRepository->method('findByPersonAndDay')->willReturn(null);
        $this->assignmentRepository->method('findByPersonAndDay')->willReturn(null);
        $this->assignmentRepository->method('findByTaskAndDay')->willReturn(null);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $assignment = $this->service->assign($person, $task, $day);

        $this->assertInstanceOf(Assignment::class, $assignment);
    }

    public function testAssignFailsWhenPersonIsAbsent(): void
    {
        $person = $this->createPersonWithId(1);
        $task = $this->createTaskWithId(1);
        $day = $this->createDayWithId(1);

        $this->absenceRepository->method('findAbsencesForPersonOnDate')
            ->willReturn([new \App\Entity\Absence()]);

        $this->expectException(\DomainException::class);
        $this->service->assign($person, $task, $day);
    }

    public function testAssignFailsWhenPersonHasExternalTask(): void
    {
        $person = $this->createPersonWithId(1);
        $task = $this->createTaskWithId(1);
        $day = $this->createDayWithId(1);

        $this->absenceRepository->method('findAbsencesForPersonOnDate')->willReturn([]);
        $this->externalTaskRepository->method('findByPersonAndDay')
            ->willReturn(new \App\Entity\ExternalTask());

        $this->expectException(\DomainException::class);
        $this->service->assign($person, $task, $day);
    }

    public function testAssignFailsWhenPersonAlreadyAssigned(): void
    {
        $person = $this->createPersonWithId(1);
        $task = $this->createTaskWithId(1);
        $day = $this->createDayWithId(1);

        $this->absenceRepository->method('findAbsencesForPersonOnDate')->willReturn([]);
        $this->externalTaskRepository->method('findByPersonAndDay')->willReturn(null);
        $this->assignmentRepository->method('findByPersonAndDay')
            ->willReturn(new Assignment());

        $this->expectException(\DomainException::class);
        $this->service->assign($person, $task, $day);
    }

    public function testAssignFailsWhenTaskAlreadyAssigned(): void
    {
        $person = $this->createPersonWithId(1);
        $task = $this->createTaskWithId(1);
        $day = $this->createDayWithId(1);

        $this->absenceRepository->method('findAbsencesForPersonOnDate')->willReturn([]);
        $this->externalTaskRepository->method('findByPersonAndDay')->willReturn(null);
        $this->assignmentRepository->method('findByPersonAndDay')->willReturn(null);
        $this->assignmentRepository->method('findByTaskAndDay')
            ->willReturn(new Assignment());

        $this->expectException(\DomainException::class);
        $this->service->assign($person, $task, $day);
    }

    private function createPersonWithId(int $id): Person
    {
        $person = new Person();
        $person->setName('Test Person');
        $reflection = new \ReflectionClass($person);
        $prop = $reflection->getProperty('id');
        $prop->setValue($person, $id);
        $prop->setAccessible(false);
        return $person;
    }

    private function createTaskWithId(int $id): Task
    {
        $task = new Task();
        $task->setName('Test Task');
        $reflection = new \ReflectionClass($task);
        $prop = $reflection->getProperty('id');
        $prop->setValue($task, $id);
        return $task;
    }

    private function createDayWithId(int $id): Day
    {
        $day = new Day();
        $reflection = new \ReflectionClass($day);
        $prop = $reflection->getProperty('id');
        $prop->setValue($day, $id);
        $day->setDate(new \DateTimeImmutable('today'));
        return $day;
    }
}
