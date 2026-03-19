<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assembly;
use App\Entity\Day;
use App\Entity\SpecialDate;
use App\Enum\SpecialDateType;
use App\Repository\DayRepository;
use App\Repository\SpecialDateRepository;
use App\Service\PlanningRuleService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PlanningRuleServiceTest extends TestCase
{
    private PlanningRuleService $service;
    private MockObject&SpecialDateRepository $specialDateRepository;
    private MockObject&DayRepository $dayRepository;
    private MockObject&EntityManagerInterface $entityManager;
    private Assembly $assembly;

    protected function setUp(): void
    {
        $this->specialDateRepository = $this->createMock(SpecialDateRepository::class);
        $this->dayRepository = $this->createMock(DayRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new PlanningRuleService(
            $this->specialDateRepository,
            $this->dayRepository,
            $this->entityManager,
        );

        $this->assembly = new Assembly();
        $this->assembly->setName('Test Assembly');
        $this->assembly->setWeekdays([3]); // Wednesday

        $reflection = new \ReflectionClass($this->assembly);
        $prop = $reflection->getProperty('id');
        $prop->setValue($this->assembly, 1);
    }

    public function testMemorialBlocksDay(): void
    {
        $date = new DateTimeImmutable('2026-03-11'); // Wednesday
        $day = $this->createDayWithDate(1, $date);

        $specialDate = $this->createSpecialDate(SpecialDateType::MEMORIAL, $date, $date);

        $this->specialDateRepository->method('findByAssemblyAndPeriod')->willReturn([$specialDate]);

        $result = $this->service->applyRules($this->assembly, [$day], 2026, 3);

        $this->assertCount(1, $result);
        $entry = reset($result);
        $this->assertTrue($entry['isBlocked']);
        $this->assertSame('planning.label.memorial', $entry['specialLabel']);
    }

    public function testCongressBlocksCongressWeek(): void
    {
        // Kongress am Samstag 2026-03-21 (Kalenderwoche: Mo 16.03 – So 22.03)
        $congressStart = new DateTimeImmutable('2026-03-21');
        $congressEnd   = new DateTimeImmutable('2026-03-21');

        // Donnerstag 19.03 und Sonntag 22.03 liegen in der Kongresswoche → blockiert
        $thursday = $this->createDayWithDate(1, new DateTimeImmutable('2026-03-19'));
        $sunday   = $this->createDayWithDate(2, new DateTimeImmutable('2026-03-22'));
        // Donnerstag 26.03 liegt nach der Kongresswoche → unverändert
        $afterDay = $this->createDayWithDate(3, new DateTimeImmutable('2026-03-26'));

        $specialDate = $this->createSpecialDate(SpecialDateType::CONGRESS, $congressStart, $congressEnd);
        $this->specialDateRepository->method('findByAssemblyAndPeriod')->willReturn([$specialDate]);

        $result = $this->service->applyRules($this->assembly, [$thursday, $sunday, $afterDay], 2026, 3);

        $this->assertArrayHasKey(1, $result, 'Donnerstag 19.03 muss im Grid sichtbar bleiben');
        $this->assertTrue($result[1]['isBlocked'], 'Donnerstag 19.03 muss blockiert sein');
        $this->assertSame('planning.label.congress', $result[1]['specialLabel']);

        $this->assertArrayHasKey(2, $result, 'Sonntag 22.03 muss im Grid sichtbar bleiben');
        $this->assertTrue($result[2]['isBlocked'], 'Sonntag 22.03 muss blockiert sein');

        $this->assertArrayHasKey(3, $result);
        $this->assertFalse($result[3]['isBlocked']);
    }

    public function testCongressDoesNotAffectPreviousWeek(): void
    {
        // Kongress am Samstag 2026-03-21 – Woche davor (Mo 09.03 – So 15.03) bleibt unberührt
        $congressStart = new DateTimeImmutable('2026-03-21');
        $congressEnd   = new DateTimeImmutable('2026-03-21');

        $prevThursday = $this->createDayWithDate(1, new DateTimeImmutable('2026-03-12'));

        $specialDate = $this->createSpecialDate(SpecialDateType::CONGRESS, $congressStart, $congressEnd);
        $this->specialDateRepository->method('findByAssemblyAndPeriod')->willReturn([$specialDate]);

        $result = $this->service->applyRules($this->assembly, [$prevThursday], 2026, 3);

        $this->assertArrayHasKey(1, $result, 'Donnerstag 12.03 (Vorwoche) muss erhalten bleiben');
        $this->assertFalse($result[1]['isBlocked']);
    }

    public function testMiscBlocksWeekendPlanningWhenDateIsWeekend(): void
    {
        // Sonstiges am Samstag 2026-03-21 → Sonntag 22.03 (Wochenend-Planungstag) wird blockiert
        $miscDate    = new DateTimeImmutable('2026-03-21'); // Saturday
        $thursday    = $this->createDayWithDate(1, new DateTimeImmutable('2026-03-19'));
        $sunday      = $this->createDayWithDate(2, new DateTimeImmutable('2026-03-22'));

        $specialDate = $this->createSpecialDate(SpecialDateType::MISC, $miscDate, $miscDate);
        $this->specialDateRepository->method('findByAssemblyAndPeriod')->willReturn([$specialDate]);

        $result = $this->service->applyRules($this->assembly, [$thursday, $sunday], 2026, 3);

        // Sonntag (Wochenend-Planungstag) wird blockiert
        $this->assertTrue($result[2]['isBlocked']);
        $this->assertSame('planning.label.misc', $result[2]['specialLabel']);
        // Donnerstag (Wochentags-Planungstag) bleibt frei
        $this->assertFalse($result[1]['isBlocked']);
    }

    public function testMiscBlocksWeekdayPlanningWhenDateIsWeekday(): void
    {
        // Sonstiges am Mittwoch 2026-03-18 → Donnerstag 19.03 (Wochentags-Planungstag) wird blockiert
        $miscDate = new DateTimeImmutable('2026-03-18'); // Wednesday
        $thursday = $this->createDayWithDate(1, new DateTimeImmutable('2026-03-19'));
        $sunday   = $this->createDayWithDate(2, new DateTimeImmutable('2026-03-22'));

        $specialDate = $this->createSpecialDate(SpecialDateType::MISC, $miscDate, $miscDate);
        $this->specialDateRepository->method('findByAssemblyAndPeriod')->willReturn([$specialDate]);

        $result = $this->service->applyRules($this->assembly, [$thursday, $sunday], 2026, 3);

        // Donnerstag (Wochentags-Planungstag) wird blockiert
        $this->assertTrue($result[1]['isBlocked']);
        $this->assertSame('planning.label.misc', $result[1]['specialLabel']);
        // Sonntag (Wochenend-Planungstag) bleibt frei
        $this->assertFalse($result[2]['isBlocked']);
    }

    public function testNoSpecialDatesLeavesGridUnchanged(): void
    {
        $day1 = $this->createDayWithDate(1, new DateTimeImmutable('2026-03-11'));
        $day2 = $this->createDayWithDate(2, new DateTimeImmutable('2026-03-18'));

        $this->specialDateRepository->method('findByAssemblyAndPeriod')->willReturn([]);

        $result = $this->service->applyRules($this->assembly, [$day1, $day2], 2026, 3);

        $this->assertCount(2, $result);
        $this->assertFalse($result[1]['isBlocked']);
        $this->assertFalse($result[2]['isBlocked']);
        $this->assertNull($result[1]['specialLabel']);
        $this->assertNull($result[2]['specialLabel']);
    }

    public function testResultIsSortedByDate(): void
    {
        $day1 = $this->createDayWithDate(1, new DateTimeImmutable('2026-03-25'));
        $day2 = $this->createDayWithDate(2, new DateTimeImmutable('2026-03-04'));

        $this->specialDateRepository->method('findByAssemblyAndPeriod')->willReturn([]);

        $result = $this->service->applyRules($this->assembly, [$day1, $day2], 2026, 3);

        $dates = array_map(fn($e) => $e['day']->getDate()->format('Y-m-d'), $result);
        $this->assertSame(['2026-03-04', '2026-03-25'], array_values($dates));
    }

    private function createDayWithDate(int $id, DateTimeImmutable $date): Day
    {
        $day = new Day();
        $day->setDate($date);
        $day->setAssembly($this->assembly);

        $reflection = new \ReflectionClass($day);
        $prop = $reflection->getProperty('id');
        $prop->setValue($day, $id);

        return $day;
    }

    private function createSpecialDate(SpecialDateType $type, DateTimeImmutable $start, DateTimeImmutable $end): SpecialDate
    {
        $specialDate = new SpecialDate();
        $specialDate->setAssembly($this->assembly);
        $specialDate->setType($type->value);
        $specialDate->setStartDate($start);
        $specialDate->setEndDate($end);

        return $specialDate;
    }
}
