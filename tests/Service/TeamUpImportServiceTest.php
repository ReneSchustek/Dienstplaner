<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Absence;
use App\Entity\Assembly;
use App\Entity\Person;
use App\Repository\AbsenceRepository;
use App\Repository\PersonRepository;
use App\Service\TeamUpClient;
use App\Service\TeamUpImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TeamUpImportServiceTest extends TestCase
{
    private TeamUpImportService $service;
    private MockObject&TeamUpClient $teamUpClient;
    private MockObject&PersonRepository $personRepository;
    private MockObject&AbsenceRepository $absenceRepository;
    private MockObject&EntityManagerInterface $entityManager;
    private Assembly $assembly;

    protected function setUp(): void
    {
        $this->teamUpClient = $this->createMock(TeamUpClient::class);
        $this->personRepository = $this->createMock(PersonRepository::class);
        $this->absenceRepository = $this->createMock(AbsenceRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new TeamUpImportService(
            $this->teamUpClient,
            $this->personRepository,
            $this->absenceRepository,
            $this->entityManager,
        );

        $this->assembly = new Assembly();
        $this->assembly->setName('Test Assembly');

        $reflection = new \ReflectionClass($this->assembly);
        $prop = $reflection->getProperty('id');
        $prop->setValue($this->assembly, 1);
    }

    public function testImportCreatesAbsenceForMatchingPerson(): void
    {
        $person = $this->createPersonWithName('Max Mustermann');

        $this->teamUpClient->method('fetchEvents')->willReturn([
            [
                'title' => 'Max Mustermann',
                'start_dt' => '2026-03-10',
                'end_dt' => '2026-03-12',
            ],
        ]);

        $this->personRepository->method('findByAssembly')->willReturn([$person]);
        $this->absenceRepository->method('findAbsencesForPersonOnDate')->willReturn([]);
        $this->entityManager->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(Absence::class));
        $this->entityManager->expects($this->once())->method('flush');

        $count = $this->service->importAbsencesForAssembly(
            $this->assembly,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31'),
        );

        $this->assertSame(1, $count);
    }

    public function testImportSkipsUnknownPerson(): void
    {
        $this->teamUpClient->method('fetchEvents')->willReturn([
            [
                'title' => 'Unknown Person',
                'start_dt' => '2026-03-10',
                'end_dt' => '2026-03-10',
            ],
        ]);

        $this->personRepository->method('findByAssembly')->willReturn([
            $this->createPersonWithName('Max Mustermann'),
        ]);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $count = $this->service->importAbsencesForAssembly(
            $this->assembly,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31'),
        );

        $this->assertSame(0, $count);
    }

    public function testImportSkipsAlreadyExistingAbsence(): void
    {
        $person = $this->createPersonWithName('Max Mustermann');

        $this->teamUpClient->method('fetchEvents')->willReturn([
            [
                'title' => 'Max Mustermann',
                'start_dt' => '2026-03-10',
                'end_dt' => '2026-03-10',
            ],
        ]);

        $this->personRepository->method('findByAssembly')->willReturn([$person]);
        $this->absenceRepository->method('findAbsencesForPersonOnDate')
            ->willReturn([new Absence()]);

        $this->entityManager->expects($this->never())->method('persist');

        $count = $this->service->importAbsencesForAssembly(
            $this->assembly,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31'),
        );

        $this->assertSame(0, $count);
    }

    public function testImportReturnsCorrectCount(): void
    {
        $person1 = $this->createPersonWithName('Anna Schmidt');
        $person2 = $this->createPersonWithName('Bernd Müller');

        $this->teamUpClient->method('fetchEvents')->willReturn([
            ['title' => 'Anna Schmidt', 'start_dt' => '2026-03-10', 'end_dt' => '2026-03-10'],
            ['title' => 'Bernd Müller', 'start_dt' => '2026-03-11', 'end_dt' => '2026-03-11'],
            ['title' => 'Unknown', 'start_dt' => '2026-03-12', 'end_dt' => '2026-03-12'],
        ]);

        $this->personRepository->method('findByAssembly')->willReturn([$person1, $person2]);
        $this->absenceRepository->method('findAbsencesForPersonOnDate')->willReturn([]);
        $this->entityManager->expects($this->exactly(2))->method('persist');

        $count = $this->service->importAbsencesForAssembly(
            $this->assembly,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31'),
        );

        $this->assertSame(2, $count);
    }

    private function createPersonWithName(string $name): Person
    {
        $person = new Person();
        $person->setName($name);

        $reflection = new \ReflectionClass($person);
        $prop = $reflection->getProperty('id');
        $prop->setValue($person, crc32($name));

        return $person;
    }
}
