<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Person;
use App\Service\PdfImportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PdfImportServiceTest extends TestCase
{
    private PdfImportService $service;

    protected function setUp(): void
    {
        $this->service = new PdfImportService(new NullLogger());
    }

    private function makePerson(int $id, string $name): Person
    {
        $person = new Person();
        $person->setName($name);

        $ref = new \ReflectionProperty(Person::class, 'id');
        $ref->setValue($person, $id);

        return $person;
    }

    public function testExtractWeekStartDateApril(): void
    {
        $method = new \ReflectionMethod(PdfImportService::class, 'extractWeekStartDate');

        $result = $method->invoke($this->service, '6.-12. APRIL | JESAJA 50-51', 2026);

        $this->assertSame('2026-04-06', $result);
    }

    public function testExtractWeekStartDateCrossMonth(): void
    {
        $method = new \ReflectionMethod(PdfImportService::class, 'extractWeekStartDate');

        $result = $method->invoke($this->service, '27. APRIL–3. MAI | JESAJA 56-57', 2026);

        $this->assertSame('2026-04-27', $result);
    }

    public function testExtractWeekStartDateNotAHeader(): void
    {
        $method = new \ReflectionMethod(PdfImportService::class, 'extractWeekStartDate');

        $this->assertNull($method->invoke($this->service, '19:06 1. Einige Teilnehmer (10 Min.)', 2026));
        $this->assertNull($method->invoke($this->service, 'Dortmund West', 2026));
    }

    public function testMatchPersonExact(): void
    {
        $method   = new \ReflectionMethod(PdfImportService::class, 'matchPerson');
        $persons  = [$this->makePerson(1, 'Erler, Patrick')];
        $exactMap = ['erler, patrick' => $persons[0]];

        $result = $method->invoke($this->service, 'erler, patrick', $persons, $exactMap);

        $this->assertSame(1, $result['person_id']);
        $this->assertSame('exact', $result['match_type']);
    }

    public function testMatchPersonByLastName(): void
    {
        $method   = new \ReflectionMethod(PdfImportService::class, 'matchPerson');
        $persons  = [$this->makePerson(2, 'Müller, Holger')];
        $exactMap = ['müller, holger' => $persons[0]];

        // PDF name "H.Müller" → token "müller" matches
        $result = $method->invoke($this->service, 'H.Müller', $persons, $exactMap);

        $this->assertSame(2, $result['person_id']);
        $this->assertSame('partial', $result['match_type']);
    }

    public function testMatchPersonNoMatch(): void
    {
        $method   = new \ReflectionMethod(PdfImportService::class, 'matchPerson');
        $persons  = [$this->makePerson(1, 'Erler, Patrick')];
        $exactMap = ['erler, patrick' => $persons[0]];

        $result = $method->invoke($this->service, 'Unbekannt, X', $persons, $exactMap);

        $this->assertNull($result['person_id']);
        $this->assertSame('none', $result['match_type']);
    }

    public function testParseMeetingProgramFormatNoPersons(): void
    {
        $method = new \ReflectionMethod(PdfImportService::class, 'parseMeetingProgramFormat');

        $lines = [
            '6.-12. APRIL | JESAJA 50-51 	Vorsitzender: E.Obermann',
            '19:06 1. Höre auf den Schüler (10 Min.) 	P.Erler',
            '20:06 8. Versammlungsbibelstudium (30 Min.) 	Leiter/Leser: A.Simon/Di.Bahr',
        ];

        $items = $method->invoke($this->service, $lines, []);

        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertNull($item['person_id']);
            $this->assertSame('none', $item['match_type']);
        }
    }

    public function testParseMeetingProgramFormatWithPersons(): void
    {
        $method = new \ReflectionMethod(PdfImportService::class, 'parseMeetingProgramFormat');

        $persons = [
            $this->makePerson(1, 'Erler, Patrick'),
            $this->makePerson(2, 'Simon, André'),
            $this->makePerson(3, 'Bahr, Dieter'),
        ];

        $lines = [
            '6.-12. APRIL | JESAJA 50-51',
            '19:06 1. Höre auf den Schüler (10 Min.) 	P.Erler',
            '20:06 8. Versammlungsbibelstudium (30 Min.) 	Leiter/Leser: A.Simon/Di.Bahr',
        ];

        $items = $method->invoke($this->service, $lines, $persons);

        $this->assertCount(3, $items);

        $dates      = array_column($items, 'date');
        $personIds  = array_column($items, 'person_id');

        // All in same week (April 6)
        $this->assertSame(['2026-04-06', '2026-04-06', '2026-04-06'], $dates);

        // All three persons matched
        $this->assertContains(1, $personIds);
        $this->assertContains(2, $personIds);
        $this->assertContains(3, $personIds);
    }

    public function testSeenInWeekPreventsPersonDuplicates(): void
    {
        $method = new \ReflectionMethod(PdfImportService::class, 'parseMeetingProgramFormat');

        $persons = [$this->makePerson(1, 'Erler, Patrick')];

        // Erler appears twice in same week
        $lines = [
            '6.-12. APRIL | JESAJA 50-51',
            '19:06 1. Erste Aufgabe (10 Min.) 	P.Erler',
            '19:16 2. Zweite Aufgabe (10 Min.) 	P.Erler',
        ];

        $items = $method->invoke($this->service, $lines, $persons);

        // Only one entry per person per week
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]['person_id']);
    }

    public function testMultipleWeeksCreateSeparateEntries(): void
    {
        $method = new \ReflectionMethod(PdfImportService::class, 'parseMeetingProgramFormat');

        $persons = [$this->makePerson(1, 'Erler, Patrick')];

        $lines = [
            '6.-12. APRIL | JESAJA 50-51',
            '19:06 1. Erste Woche (10 Min.) 	P.Erler',
            '13.-19. APRIL | JESAJA 52-53',
            '19:06 1. Zweite Woche (10 Min.) 	P.Erler',
        ];

        $items = $method->invoke($this->service, $lines, $persons);

        // One entry per week
        $this->assertCount(2, $items);
        $this->assertSame('2026-04-06', $items[0]['date']);
        $this->assertSame('2026-04-13', $items[1]['date']);
    }

    public function testExtractTaskDescription(): void
    {
        $method = new \ReflectionMethod(PdfImportService::class, 'extractTaskDescription');

        $line   = '19:06 1. Höre auf den Schüler (10 Min.) 	P.Erler';
        $result = $method->invoke($this->service, $line);

        $this->assertSame('Höre auf den Schüler', $result);
    }

    public function testExtractTaskDescriptionWithLabel(): void
    {
        $method = new \ReflectionMethod(PdfImportService::class, 'extractTaskDescription');

        $line   = '20:06 8. Versammlungsbibelstudium (30 Min.) 	Leiter/Leser: A.Simon/Di.Bahr';
        $result = $method->invoke($this->service, $line);

        $this->assertSame('Versammlungsbibelstudium', $result);
    }

    public function testStandardFormatDateExtraction(): void
    {
        $method = new \ReflectionMethod(PdfImportService::class, 'extractStandardDate');

        $this->assertSame('2026-04-15', $method->invoke($this->service, '15.04.2026 Müller, Hans'));
        $this->assertSame('2026-03-01', $method->invoke($this->service, '01.03.26 Test'));
        $this->assertSame('2026-04-15', $method->invoke($this->service, '2026-04-15 something'));
        $this->assertNull($method->invoke($this->service, 'Keine Datumsangabe hier'));
    }
}
