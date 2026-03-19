<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Assignment;
use App\Entity\Day;
use App\Repository\AssemblyRepository;
use App\Repository\DayRepository;
use App\Repository\ExternalTaskRepository;
use App\Repository\PersonRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Liest PLANUNG_BISHER.md und erstellt daraus korrekte Assignment-Einträge.
 * ExternalTask-Einträge mit Task-Namen als Beschreibung werden gelöscht.
 */
#[AsCommand(
    name: 'app:migrate-planning-data',
    description: 'Konvertiert ExternalTasks mit Task-Namen zu Assignments (Datenmigration)',
)]
class MigratePlanningDataCommand extends Command
{
    /**
     * Explizite Namensmappings für Abweichungen zwischen PLANUNG_BISHER.md und DB.
     * Format: 'PLANUNG_BISHER-Name' => 'DB-Name (Nachname, Vorname)'
     */
    private const NAME_MAP = [
        'Harry Glebe'     => 'Gliebe, Harry',
        'Siegbet Hüwel'   => 'Hüwel, Siegbert',
        'İlhan Ünsal'     => 'Ünşan, İlhan',
        'René Schustek'   => 'Schustek, René',
    ];

    /** Einträge die übersprungen werden (keine Assignment-Erstellung) */
    private const SKIP_VALUES = ['Andere Aufgabe', 'Abwesend', 'Kongress', 'ALLE'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssemblyRepository $assemblyRepository,
        private readonly PersonRepository $personRepository,
        private readonly TaskRepository $taskRepository,
        private readonly DayRepository $dayRepository,
        private readonly ExternalTaskRepository $externalTaskRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'file',
            'f',
            InputOption::VALUE_OPTIONAL,
            'Pfad zur PLANUNG_BISHER.md',
            '/var/www/html/var/PLANUNG_BISHER.md',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Nur anzeigen, was gemacht würde (keine DB-Änderungen)',
        );
        $this->addOption(
            'assembly',
            'a',
            InputOption::VALUE_OPTIONAL,
            'Assembly-ID',
            '1',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $file = $input->getOption('file');
        $assemblyId = (int) $input->getOption('assembly');

        if (!file_exists($file)) {
            $io->error("Datei nicht gefunden: $file");
            return Command::FAILURE;
        }

        $assembly = $this->assemblyRepository->find($assemblyId);
        if ($assembly === null) {
            $io->error("Assembly $assemblyId nicht gefunden.");
            return Command::FAILURE;
        }

        // Lade alle Personen und Aufgaben in Lookup-Maps
        $personMap = $this->buildPersonMap($assemblyId);
        $taskMap   = $this->buildTaskMap($assemblyId);
        $taskNames = array_keys($taskMap);

        $io->section("Assembly: {$assembly->getName()}");
        $io->text("Tasks: " . implode(', ', $taskNames));

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $created = 0;
        $skipped = 0;
        $notFound = [];
        // Verhindet doppelte (task_id, day_key)-Kombinationen
        $seenTaskDay = [];

        foreach ($lines as $line) {
            // Format: DD.MM.YYYY – Name – Aufgabe
            if (!preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+–\s+(.+?)\s+–\s+(.+)$/', $line, $m)) {
                continue;
            }

            [, $dateStr, $personName, $taskName] = $m;

            // Überspringe nicht-relevante Einträge
            if (in_array($taskName, self::SKIP_VALUES, true)) {
                continue;
            }

            // Überspringe "ALLE – Kongress" etc.
            if ($personName === 'ALLE') {
                continue;
            }

            // Prüfe ob der Task-Name bekannt ist
            if (!isset($taskMap[$taskName])) {
                $io->warning("Unbekannte Aufgabe '$taskName' für $personName am $dateStr – übersprungen");
                $skipped++;
                continue;
            }

            // Person auflösen
            $person = $this->resolvePerson($personName, $personMap);
            if ($person === null) {
                $notFound[] = "$personName ($dateStr)";
                $skipped++;
                continue;
            }

            // Datum parsen
            $date = \DateTimeImmutable::createFromFormat('d.m.Y', $dateStr);
            if ($date === false) {
                $io->warning("Ungültiges Datum: $dateStr");
                $skipped++;
                continue;
            }

            $task = $taskMap[$taskName];

            // Day finden oder erstellen
            $day = $this->dayRepository->findByAssemblyAndDate($assemblyId, $date);
            if ($day === null) {
                $day = new Day();
                $day->setDate($date);
                $day->setAssembly($assembly);
                if (!$dryRun) {
                    $this->em->persist($day);
                    $this->em->flush();
                }
            }

            // Prüfe ob Assignment bereits existiert
            $existing = $this->em->createQueryBuilder()
                ->select('a')
                ->from(Assignment::class, 'a')
                ->where('a.day = :day AND a.task = :task')
                ->setParameter('day', $day)
                ->setParameter('task', $task)
                ->getQuery()
                ->getOneOrNullResult();

            if ($existing !== null) {
                $io->text("  Bereits vorhanden: $personName – $taskName am $dateStr");
                continue;
            }

            $io->text("  <info>Erstelle</info>: $personName – $taskName am $dateStr");

            if (!$dryRun) {
                $assignment = new Assignment();
                $assignment->setDay($day);
                $assignment->setTask($task);
                $assignment->setPerson($person);
                $this->em->persist($assignment);
                $created++;
            } else {
                $created++;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success("Assignments erstellt: $created, übersprungen: $skipped");

        if (!empty($notFound)) {
            $io->warning("Personen nicht gefunden:\n" . implode("\n", $notFound));
        }

        // Lösche ExternalTasks, die jetzt durch Assignments ersetzt wurden
        $taskNameList = array_keys($taskMap);
        $externalToDelete = $this->externalTaskRepository->findByDescriptions($taskNameList, $assemblyId);

        $io->section("ExternalTasks löschen (Task-Namen als Beschreibung)");
        $io->text("Gefunden: " . count($externalToDelete));

        if (!$dryRun) {
            foreach ($externalToDelete as $et) {
                $this->em->remove($et);
            }
            $this->em->flush();
            $io->success(count($externalToDelete) . " ExternalTasks gelöscht.");
        } else {
            $io->note("Dry-run: würde " . count($externalToDelete) . " ExternalTasks löschen.");
        }

        return Command::SUCCESS;
    }

    private function buildPersonMap(int $assemblyId): array
    {
        $persons = $this->personRepository->findByAssembly($assemblyId);
        $map = [];
        foreach ($persons as $p) {
            $map[$p->getName()] = $p;
        }
        return $map;
    }

    private function buildTaskMap(int $assemblyId): array
    {
        $tasks = $this->taskRepository->findByAssembly($assemblyId);
        $map = [];
        foreach ($tasks as $t) {
            $map[$t->getName()] = $t;
        }
        return $map;
    }

    private function resolvePerson(string $planningName, array $personMap): ?object
    {
        // Explizites Mapping für Sonderfälle
        if (isset(self::NAME_MAP[$planningName])) {
            $dbName = self::NAME_MAP[$planningName];
            return $personMap[$dbName] ?? null;
        }

        // Standard: "Vorname Nachname" → "Nachname, Vorname"
        $parts = explode(' ', $planningName, 2);
        if (count($parts) === 2) {
            $reversed = $parts[1] . ', ' . $parts[0];
            if (isset($personMap[$reversed])) {
                return $personMap[$reversed];
            }
        }

        // Direkter Treffer (falls Name bereits im DB-Format vorliegt)
        if (isset($personMap[$planningName])) {
            return $personMap[$planningName];
        }

        return null;
    }
}
