<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assembly;
use App\Service\AssemblyService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class AssemblyServiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // validateWeekdays
    // -------------------------------------------------------------------------

    public function testValidateWeekdaysAcceptsDiAndSa(): void
    {
        $this->assertTrue(AssemblyService::validateWeekdays([2, 6]));
    }

    public function testValidateWeekdaysAcceptsMoAndSo(): void
    {
        $this->assertTrue(AssemblyService::validateWeekdays([1, 0]));
    }

    public function testValidateWeekdaysRejectsTwoWeekdays(): void
    {
        $this->assertFalse(AssemblyService::validateWeekdays([1, 2]));
    }

    public function testValidateWeekdaysRejectsTwoWeekendDays(): void
    {
        $this->assertFalse(AssemblyService::validateWeekdays([6, 0]));
    }

    public function testValidateWeekdaysRejectsSingleDay(): void
    {
        $this->assertFalse(AssemblyService::validateWeekdays([2]));
    }

    public function testValidateWeekdaysRejectsThreeDays(): void
    {
        $this->assertFalse(AssemblyService::validateWeekdays([1, 2, 6]));
    }

    public function testValidateWeekdaysRejectsEmpty(): void
    {
        $this->assertFalse(AssemblyService::validateWeekdays([]));
    }

    // -------------------------------------------------------------------------
    // resolveAssemblyDate
    // -------------------------------------------------------------------------

    private function makeAssembly(array $weekdays): Assembly
    {
        $em       = $this->createMock(EntityManagerInterface::class);
        $assembly = new Assembly();
        $assembly->setWeekdays($weekdays);
        return $assembly;
    }

    public function testResolveMapsWeekdayToConfiguredWeekday(): void
    {
        // Versammlung: Di (2) und Sa (6)
        // Import-Datum: Mittwoch 2026-03-18 → soll auf Di 2026-03-17 fallen
        $assembly = $this->makeAssembly([2, 6]);
        $service  = new AssemblyService($this->createMock(EntityManagerInterface::class));

        $result = $service->resolveAssemblyDate(new \DateTimeImmutable('2026-03-18'), $assembly);

        $this->assertSame('2026-03-17', $result->format('Y-m-d'));
    }

    public function testResolveMapsWeekendToConfiguredWeekendDay(): void
    {
        // Versammlung: Di (2) und Sa (6)
        // Import-Datum: Sonntag 2026-03-22 → soll auf Sa 2026-03-21 fallen
        $assembly = $this->makeAssembly([2, 6]);
        $service  = new AssemblyService($this->createMock(EntityManagerInterface::class));

        $result = $service->resolveAssemblyDate(new \DateTimeImmutable('2026-03-22'), $assembly);

        $this->assertSame('2026-03-21', $result->format('Y-m-d'));
    }

    public function testResolveReturnsSameDayIfNoMatchingConfig(): void
    {
        // Versammlung: nur Wochentage → kein Wochenendtag konfiguriert
        // Import-Datum liegt auf Sa → kein Treffer → Originalwert zurück
        $assembly = $this->makeAssembly([2, 3]);
        $service  = new AssemblyService($this->createMock(EntityManagerInterface::class));

        $date   = new \DateTimeImmutable('2026-03-21'); // Samstag
        $result = $service->resolveAssemblyDate($date, $assembly);

        $this->assertSame('2026-03-21', $result->format('Y-m-d'));
    }

    public function testResolveSundayConfiguredAsSo(): void
    {
        // Versammlung: Di (2) und So (0)
        // Import-Datum: Samstag 2026-03-21 → soll auf So 2026-03-22 fallen
        $assembly = $this->makeAssembly([2, 0]);
        $service  = new AssemblyService($this->createMock(EntityManagerInterface::class));

        $result = $service->resolveAssemblyDate(new \DateTimeImmutable('2026-03-21'), $assembly);

        $this->assertSame('2026-03-22', $result->format('Y-m-d'));
    }
}
