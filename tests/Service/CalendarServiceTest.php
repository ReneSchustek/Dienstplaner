<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Absence;
use App\Entity\Person;
use App\Entity\User;
use App\Repository\AbsenceRepository;
use App\Repository\AssignmentRepository;
use App\Repository\ExternalTaskRepository;
use App\Repository\SpecialDateRepository;
use App\Service\AssemblyContext;
use App\Service\CalendarService;
use App\Service\PlanerScope;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CalendarServiceTest extends TestCase
{
    private CalendarService $service;
    private MockObject&AbsenceRepository $absenceRepository;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&AssemblyContext $assemblyContext;

    protected function setUp(): void
    {
        $this->absenceRepository = $this->createMock(AbsenceRepository::class);
        $this->entityManager     = $this->createMock(EntityManagerInterface::class);
        $this->assemblyContext   = $this->createMock(AssemblyContext::class);

        $this->service = new CalendarService(
            $this->absenceRepository,
            $this->createMock(AssignmentRepository::class),
            $this->createMock(ExternalTaskRepository::class),
            $this->createMock(SpecialDateRepository::class),
            $this->entityManager,
            $this->assemblyContext,
            $this->createMock(PlanerScope::class),
            new NullLogger(),
        );
    }

    public function testUserOwnsAbsenceReturnsTrueForOwnPerson(): void
    {
        $person = $this->createPersonWithId(5);
        $user = new User();
        $user->setPerson($person);

        $absence = new Absence();
        $absence->setPerson($person);
        $absence->setStartDate(new \DateTimeImmutable('today'));
        $absence->setEndDate(new \DateTimeImmutable('today'));

        $this->assertTrue($this->service->userOwnsAbsence($user, $absence));
    }

    public function testUserOwnsAbsenceReturnsFalseForOtherPerson(): void
    {
        $ownPerson = $this->createPersonWithId(1);
        $otherPerson = $this->createPersonWithId(2);

        $user = new User();
        $user->setPerson($ownPerson);

        $absence = new Absence();
        $absence->setPerson($otherPerson);
        $absence->setStartDate(new \DateTimeImmutable('today'));
        $absence->setEndDate(new \DateTimeImmutable('today'));

        $this->assertFalse($this->service->userOwnsAbsence($user, $absence));
    }

    public function testUserOwnsAbsenceReturnsFalseWhenUserHasNoPerson(): void
    {
        $user = new User();
        $person = $this->createPersonWithId(1);

        $absence = new Absence();
        $absence->setPerson($person);
        $absence->setStartDate(new \DateTimeImmutable('today'));
        $absence->setEndDate(new \DateTimeImmutable('today'));

        $this->assertFalse($this->service->userOwnsAbsence($user, $absence));
    }

    private function createPersonWithId(int $id): Person
    {
        $person = new Person();
        $reflection = new \ReflectionClass($person);
        $prop = $reflection->getProperty('id');
        $prop->setValue($person, $id);
        return $person;
    }
}
