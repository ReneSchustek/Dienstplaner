<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Assembly;
use App\Entity\Day;
use App\Entity\Department;
use App\Entity\ExternalTask;
use App\Entity\Person;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\AssemblyRepository;
use App\Repository\DayRepository;
use App\Repository\PersonRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Initialbefüllung für die Versammlung Dortmund-West.
 *
 * Legt Abteilungen, Aufgaben, Personen, Benutzerkonten und
 * historische externe Aufgaben (Jan–März 2026) idempotent an.
 *
 * Verwendung: php bin/console app:seed:dortmund-west
 * Mit eigenen Passwörtern: php bin/console app:seed:dortmund-west --admin-password=secret --planer-password=secret2
 */
#[AsCommand(name: 'app:seed:dortmund-west', description: 'Legt Initialdaten für Versammlung Dortmund-West an')]
class SeedDortmundWestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssemblyRepository $assemblyRepository,
        private readonly PersonRepository $personRepository,
        private readonly TaskRepository $taskRepository,
        private readonly DayRepository $dayRepository,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin-password', null, InputOption::VALUE_OPTIONAL, 'Passwort für Admin-Konto (info@ruhrcoder)', 'changeme123')
            ->addOption('planer-password', null, InputOption::VALUE_OPTIONAL, 'Passwort für Planer-Konto (jw.schustek.r@gmail.com)', 'changeme123');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Dortmund-West Initialbefüllung');

        // 1. Versammlung
        $assembly = $this->ensureAssembly();
        $io->success('Versammlung: ' . $assembly->getName());

        // 2. Abteilungen & Aufgaben
        $tasks = $this->ensureDepartmentsAndTasks($assembly);
        $io->success('Abteilungen und Aufgaben angelegt.');

        // 3. Personen
        $persons = $this->ensurePersons($assembly);
        $io->success(count($persons) . ' Personen angelegt/vorhanden.');

        // 4. Benutzerkonten
        $adminPassword = $input->getOption('admin-password');
        $planerPassword = $input->getOption('planer-password');
        $this->ensureUsers($assembly, $persons, $tasks, $adminPassword, $planerPassword);
        $io->success('Benutzerkonten angelegt/aktualisiert.');

        // 5. Personen-Aufgaben-Zuordnungen
        $this->ensurePersonTasks($persons, $tasks);
        $io->success('Personen-Aufgaben-Zuordnungen gesetzt.');

        // 6. Externe Aufgaben (historische Einsätze Jan–März 2026)
        $count = $this->ensureExternalTasks($assembly, $persons, $tasks);
        $io->success($count . ' externe Aufgaben angelegt/vorhanden.');

        $io->note('Initialbefüllung abgeschlossen. Passwörter bitte umgehend ändern.');

        return Command::SUCCESS;
    }

    private function ensureAssembly(): Assembly
    {
        $assembly = $this->assemblyRepository->findOneBy(['name' => 'Dortmund-West']);

        if ($assembly !== null) {
            return $assembly;
        }

        $assembly = new Assembly();
        $assembly->setName('Dortmund-West');
        $assembly->setWeekdays(['Thu', 'Sun']);
        $assembly->setLineColor('#1a56db');

        $this->em->persist($assembly);
        $this->em->flush();

        return $assembly;
    }

    /**
     * @return array<string, Task> Aufgaben, indiziert nach Name
     */
    private function ensureDepartmentsAndTasks(Assembly $assembly): array
    {
        $departmentData = [
            'Technik' => ['Audio / Zoom', 'Video', 'Bühne', 'Mikro L', 'Mikro R'],
            'Ordnungsdienst' => ['Eingangsordner', 'Saalordner', 'Ordner 1', 'Ordner 2'],
        ];

        $taskMap = [];

        foreach ($departmentData as $deptName => $taskNames) {
            $dept = $this->findOrCreateDepartment($assembly, $deptName);

            foreach ($taskNames as $taskName) {
                $task = $this->findOrCreateTask($dept, $taskName);
                $taskMap[$taskName] = $task;
            }
        }

        $this->em->flush();

        return $taskMap;
    }

    private function findOrCreateDepartment(Assembly $assembly, string $name): Department
    {
        foreach ($assembly->getDepartments() as $dept) {
            if ($dept->getName() === $name) {
                return $dept;
            }
        }

        $dept = new Department();
        $dept->setName($name);
        $dept->setAssembly($assembly);
        $this->em->persist($dept);

        return $dept;
    }

    private function findOrCreateTask(Department $department, string $name): Task
    {
        foreach ($department->getTasks() as $task) {
            if ($task->getName() === $name) {
                return $task;
            }
        }

        $task = new Task();
        $task->setName($name);
        $task->setDepartment($department);
        $this->em->persist($task);

        return $task;
    }

    /**
     * @return array<string, Person> Personen, indiziert nach Name
     */
    private function ensurePersons(Assembly $assembly): array
    {
        $names = [
            'Bahr, Dieter',
            'Bahr, Rainer',
            'Erler, Patrick',
            'Falch, Markus',
            'Gliebe, Harry',
            'Gohrband, Stefan',
            'Hüwel, Siegbert',
            'Juskow, Peter',
            'Knaak, Uwe',
            'Kronawitter, Robin',
            'Küstermann, Werner',
            'Lauterbach, Jasper',
            'Lindner, Karl-Heinz',
            'Morales, Sven',
            'Müller, Holger',
            'Ristau, Andreas',
            'Schustek, René',
            'Simon, André',
            'Sowa, Frank',
            'Sowa, Jan',
            'Thal, Liam',
            'Ünşan, İlhan',
            'Witkoski, Heinz',
        ];

        $personMap = [];

        foreach ($names as $name) {
            $person = $this->personRepository->findOneBy(['name' => $name, 'assembly' => $assembly]);

            if ($person === null) {
                $person = new Person();
                $person->setName($name);
                $person->setAssembly($assembly);
                $this->em->persist($person);
            }

            $personMap[$name] = $person;
        }

        $this->em->flush();

        return $personMap;
    }

    /**
     * @param array<string, Person> $persons
     * @param array<string, Task>   $tasks
     */
    private function ensureUsers(
        Assembly $assembly,
        array $persons,
        array $tasks,
        string $adminPassword,
        string $planerPassword,
    ): void {
        // Admin-Konto
        $admin = $this->userRepository->findOneBy(['email' => 'info@ruhrcoder']);

        if ($admin === null) {
            $admin = new User();
            $admin->setEmail('info@ruhrcoder');
            $admin->setRole('ROLE_ADMIN');
            $hashed = $this->passwordHasher->hashPassword($admin, $adminPassword);
            $admin->setPassword($hashed);
            $this->em->persist($admin);
        }

        if (isset($persons['Schustek, René'])) {
            $admin->setPerson($persons['Schustek, René']);
        }

        // Planer-Konto (René Schustek)
        $planer = $this->userRepository->findOneBy(['email' => 'jw.schustek.r@gmail.com']);

        if ($planer === null) {
            $planer = new User();
            $planer->setEmail('jw.schustek.r@gmail.com');
            $planer->setRole('ROLE_ASSEMBLY_ADMIN');
            $hashed = $this->passwordHasher->hashPassword($planer, $planerPassword);
            $planer->setPassword($hashed);
            $this->em->persist($planer);
        }

        $planer->setAssembly($assembly);

        if (isset($persons['Schustek, René'])) {
            $planer->setPerson($persons['Schustek, René']);

            // Aufgaben der Person (Schustek, René: Audio / Zoom, Video, Mikro L, Mikro R)
            $person = $persons['Schustek, René'];
            foreach (['Audio / Zoom', 'Video', 'Mikro L', 'Mikro R'] as $taskName) {
                if (isset($tasks[$taskName]) && !$person->hasTask($tasks[$taskName])) {
                    $person->addTask($tasks[$taskName]);
                }
            }
        }

        $this->em->flush();
    }

    /**
     * @param array<string, Person> $persons
     * @param array<string, Task>   $tasks
     */
    private function ensurePersonTasks(array $persons, array $tasks): void
    {
        $assignments = [
            'Audio / Zoom' => [
                'Gohrband, Stefan',
                'Knaak, Uwe',
                'Kronawitter, Robin',
                'Schustek, René',
                'Sowa, Frank',
                'Sowa, Jan',
                'Ünşan, İlhan',
            ],
            'Video' => [
                'Kronawitter, Robin',
                'Schustek, René',
                'Sowa, Frank',
                'Sowa, Jan',
                'Ünşan, İlhan',
            ],
            'Mikro R' => [
                'Bahr, Dieter',
                'Bahr, Rainer',
                'Gliebe, Harry',
                'Gohrband, Stefan',
                'Hüwel, Siegbert',
                'Knaak, Uwe',
                'Kronawitter, Robin',
                'Küstermann, Werner',
                'Lindner, Karl-Heinz',
                'Morales, Sven',
                'Müller, Holger',
                'Ristau, Andreas',
                'Schustek, René',
                'Simon, André',
                'Sowa, Frank',
                'Sowa, Jan',
                'Ünşan, İlhan',
                'Witkoski, Heinz',
            ],
            'Mikro L' => [
                'Bahr, Dieter',
                'Bahr, Rainer',
                'Gliebe, Harry',
                'Gohrband, Stefan',
                'Hüwel, Siegbert',
                'Knaak, Uwe',
                'Kronawitter, Robin',
                'Küstermann, Werner',
                'Lindner, Karl-Heinz',
                'Morales, Sven',
                'Müller, Holger',
                'Ristau, Andreas',
                'Schustek, René',
                'Simon, André',
                'Sowa, Frank',
                'Sowa, Jan',
                'Ünşan, İlhan',
                'Witkoski, Heinz',
            ],
            'Bühne' => [
                'Bahr, Dieter',
                'Bahr, Rainer',
                'Gliebe, Harry',
                'Gohrband, Stefan',
                'Hüwel, Siegbert',
                'Knaak, Uwe',
                'Kronawitter, Robin',
                'Lindner, Karl-Heinz',
                'Morales, Sven',
                'Müller, Holger',
                'Ristau, Andreas',
                'Schustek, René',
                'Simon, André',
                'Sowa, Frank',
                'Sowa, Jan',
                'Ünşan, İlhan',
            ],
            'Eingangsordner' => [
                'Bahr, Dieter',
                'Bahr, Rainer',
                'Erler, Patrick',
                'Gliebe, Harry',
                'Gohrband, Stefan',
                'Hüwel, Siegbert',
                'Knaak, Uwe',
                'Kronawitter, Robin',
                'Lindner, Karl-Heinz',
                'Morales, Sven',
                'Ristau, Andreas',
                'Schustek, René',
                'Simon, André',
                'Sowa, Frank',
                'Sowa, Jan',
                'Ünşan, İlhan',
                'Witkoski, Heinz',
            ],
            'Saalordner' => [
                'Bahr, Dieter',
                'Bahr, Rainer',
                'Erler, Patrick',
                'Falch, Markus',
                'Gliebe, Harry',
                'Gohrband, Stefan',
                'Hüwel, Siegbert',
                'Knaak, Uwe',
                'Kronawitter, Robin',
                'Lindner, Karl-Heinz',
                'Morales, Sven',
                'Ristau, Andreas',
                'Schustek, René',
                'Simon, André',
                'Sowa, Frank',
                'Sowa, Jan',
                'Ünşan, İlhan',
                'Witkoski, Heinz',
            ],
            'Ordner 1' => [
                'Bahr, Dieter',
                'Erler, Patrick',
                'Falch, Markus',
                'Gliebe, Harry',
                'Hüwel, Siegbert',
                'Juskow, Peter',
                'Knaak, Uwe',
                'Küstermann, Werner',
                'Lindner, Karl-Heinz',
                'Müller, Holger',
                'Ristau, Andreas',
                'Sowa, Frank',
                'Sowa, Jan',
                'Ünşan, İlhan',
                'Witkoski, Heinz',
            ],
            'Ordner 2' => [
                'Bahr, Dieter',
                'Erler, Patrick',
                'Falch, Markus',
                'Gliebe, Harry',
                'Hüwel, Siegbert',
                'Juskow, Peter',
                'Knaak, Uwe',
                'Küstermann, Werner',
                'Lindner, Karl-Heinz',
                'Müller, Holger',
                'Ristau, Andreas',
                'Sowa, Frank',
                'Sowa, Jan',
                'Ünşan, İlhan',
                'Witkoski, Heinz',
            ],
        ];

        foreach ($assignments as $taskName => $personNames) {
            if (!isset($tasks[$taskName])) {
                continue;
            }
            $task = $tasks[$taskName];
            foreach ($personNames as $personName) {
                if (!isset($persons[$personName])) {
                    continue;
                }
                $person = $persons[$personName];
                if (!$person->hasTask($task)) {
                    $person->addTask($task);
                }
            }
        }

        $this->em->flush();
    }

    /**
     * @param array<string, Person> $persons
     * @param array<string, Task>   $tasks
     */
    private function ensureExternalTasks(Assembly $assembly, array $persons, array $tasks): int
    {
        $entries = $this->getExternalTaskData();
        $count = 0;

        foreach ($entries as [$dateStr, $taskName, $personName]) {
            if (!isset($persons[$personName]) || !isset($tasks[$taskName])) {
                continue;
            }

            $date = new DateTimeImmutable($dateStr);
            $person = $persons[$personName];

            $day = $this->findOrCreateDay($assembly, $date);

            $existing = $this->em->getRepository(ExternalTask::class)->findOneBy([
                'person' => $person,
                'day' => $day,
            ]);

            if ($existing !== null) {
                continue;
            }

            $ext = new ExternalTask();
            $ext->setPerson($person);
            $ext->setDay($day);
            $ext->setDescription($taskName);
            $this->em->persist($ext);
            $count++;
        }

        $this->em->flush();

        return $count;
    }

    private function findOrCreateDay(Assembly $assembly, DateTimeImmutable $date): Day
    {
        $day = $this->dayRepository->findByAssemblyAndDate($assembly->getId(), $date);

        if ($day !== null) {
            return $day;
        }

        $day = new Day();
        $day->setAssembly($assembly);
        $day->setDate($date);
        $this->em->persist($day);
        $this->em->flush();

        return $day;
    }

    /**
     * @return array<int, array{string, string, string}> [Datum, Aufgabe, Person]
     */
    private function getExternalTaskData(): array
    {
        return [
            // Januar 2026
            ['2026-01-01', 'Eingangsordner', 'Bahr, Rainer'],
            ['2026-01-01', 'Saalordner', 'Kronawitter, Robin'],
            ['2026-01-01', 'Audio / Zoom', 'Schustek, René'],
            ['2026-01-04', 'Eingangsordner', 'Gliebe, Harry'],
            ['2026-01-04', 'Saalordner', 'Lindner, Karl-Heinz'],
            ['2026-01-04', 'Audio / Zoom', 'Ünşan, İlhan'],
            ['2026-01-08', 'Eingangsordner', 'Ristau, Andreas'],
            ['2026-01-08', 'Saalordner', 'Sowa, Frank'],
            ['2026-01-08', 'Audio / Zoom', 'Falch, Markus'],
            ['2026-01-11', 'Eingangsordner', 'Hüwel, Siegbert'],
            ['2026-01-11', 'Saalordner', 'Gohrband, Stefan'],
            ['2026-01-11', 'Audio / Zoom', 'Sowa, Jan'],
            ['2026-01-15', 'Eingangsordner', 'Ünşan, İlhan'],
            ['2026-01-15', 'Saalordner', 'Lindner, Karl-Heinz'],
            ['2026-01-15', 'Audio / Zoom', 'Thal, Liam'],
            ['2026-01-18', 'Eingangsordner', 'Ristau, Andreas'],
            ['2026-01-18', 'Saalordner', 'Sowa, Frank'],
            ['2026-01-18', 'Audio / Zoom', 'Schustek, René'],
            ['2026-01-22', 'Eingangsordner', 'Bahr, Rainer'],
            ['2026-01-22', 'Saalordner', 'Kronawitter, Robin'],
            ['2026-01-22', 'Audio / Zoom', 'Sowa, Frank'],
            ['2026-01-25', 'Eingangsordner', 'Gliebe, Harry'],
            ['2026-01-25', 'Saalordner', 'Knaak, Uwe'],
            ['2026-01-25', 'Audio / Zoom', 'Sowa, Jan'],
            ['2026-01-29', 'Eingangsordner', 'Witkoski, Heinz'],
            ['2026-01-29', 'Saalordner', 'Schustek, René'],
            ['2026-01-29', 'Audio / Zoom', 'Falch, Markus'],
            // Februar 2026
            ['2026-02-01', 'Eingangsordner', 'Sowa, Jan'],
            ['2026-02-01', 'Saalordner', 'Simon, André'],
            ['2026-02-01', 'Audio / Zoom', 'Schustek, René'],
            ['2026-02-05', 'Eingangsordner', 'Ünşan, İlhan'],
            ['2026-02-05', 'Saalordner', 'Gohrband, Stefan'],
            ['2026-02-05', 'Audio / Zoom', 'Sowa, Jan'],
            ['2026-02-08', 'Eingangsordner', 'Lindner, Karl-Heinz'],
            ['2026-02-08', 'Saalordner', 'Knaak, Uwe'],
            ['2026-02-08', 'Audio / Zoom', 'Kronawitter, Robin'],
            ['2026-02-12', 'Eingangsordner', 'Ristau, Andreas'],
            ['2026-02-12', 'Saalordner', 'Kronawitter, Robin'],
            ['2026-02-12', 'Audio / Zoom', 'Sowa, Jan'],
            ['2026-02-15', 'Eingangsordner', 'Gliebe, Harry'],
            ['2026-02-15', 'Saalordner', 'Sowa, Jan'],
            ['2026-02-15', 'Audio / Zoom', 'Falch, Markus'],
            ['2026-02-19', 'Eingangsordner', 'Gohrband, Stefan'],
            ['2026-02-19', 'Saalordner', 'Schustek, René'],
            ['2026-02-19', 'Audio / Zoom', 'Kronawitter, Robin'],
            ['2026-02-22', 'Eingangsordner', 'Simon, André'],
            ['2026-02-22', 'Saalordner', 'Sowa, Frank'],
            ['2026-02-22', 'Audio / Zoom', 'Gohrband, Stefan'],
            ['2026-02-26', 'Eingangsordner', 'Ristau, Andreas'],
            ['2026-02-26', 'Saalordner', 'Lindner, Karl-Heinz'],
            // März 2026
            ['2026-03-01', 'Eingangsordner', 'Simon, André'],
            ['2026-03-01', 'Saalordner', 'Sowa, Frank'],
            ['2026-03-01', 'Audio / Zoom', 'Ünşan, İlhan'],
            ['2026-03-05', 'Eingangsordner', 'Witkoski, Heinz'],
            ['2026-03-05', 'Saalordner', 'Lauterbach, Jasper'],
            ['2026-03-05', 'Audio / Zoom', 'Sowa, Jan'],
            ['2026-03-08', 'Eingangsordner', 'Ristau, Andreas'],
            ['2026-03-08', 'Saalordner', 'Gohrband, Stefan'],
            ['2026-03-08', 'Audio / Zoom', 'Ünşan, İlhan'],
            ['2026-03-12', 'Eingangsordner', 'Lindner, Karl-Heinz'],
            ['2026-03-12', 'Saalordner', 'Sowa, Jan'],
            ['2026-03-12', 'Audio / Zoom', 'Gohrband, Stefan'],
            ['2026-03-15', 'Eingangsordner', 'Gohrband, Stefan'],
            ['2026-03-15', 'Saalordner', 'Sowa, Frank'],
            ['2026-03-15', 'Audio / Zoom', 'Schustek, René'],
            ['2026-03-26', 'Eingangsordner', 'Knaak, Uwe'],
            ['2026-03-26', 'Saalordner', 'Simon, André'],
            ['2026-03-26', 'Audio / Zoom', 'Gohrband, Stefan'],
            ['2026-03-29', 'Eingangsordner', 'Sowa, Jan'],
            ['2026-03-29', 'Saalordner', 'Kronawitter, Robin'],
            ['2026-03-29', 'Audio / Zoom', 'Ünşan, İlhan'],
        ];
    }
}
