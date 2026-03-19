<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Day;
use App\Repository\AssemblyRepository;
use App\Repository\DayRepository;
use App\Repository\ExternalTaskRepository;
use App\Service\AssemblyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Korrigiert External-Task-Datumsangaben: mappt jedes Day-Datum auf den
 * konfigurierten Versammlungstag der jeweiligen ISO-Woche.
 */
#[AsCommand(name: 'app:fix-external-task-days', description: 'Externe Aufgaben auf korrekten Versammlungstag mappen')]
class FixExternalTaskDaysCommand extends Command
{
    public function __construct(
        private readonly AssemblyRepository $assemblyRepository,
        private readonly ExternalTaskRepository $externalTaskRepository,
        private readonly DayRepository $dayRepository,
        private readonly AssemblyService $assemblyService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $assemblies = $this->assemblyRepository->findAll();
        $moved      = 0;
        $skipped    = 0;

        foreach ($assemblies as $assembly) {
            $weekdays = $assembly->getWeekdays();
            if (!AssemblyService::validateWeekdays($weekdays)) {
                $io->warning(sprintf('Versammlung „%s" hat keine gültige Wochentags-Konfiguration – übersprungen.', $assembly->getName()));
                continue;
            }

            $tasks = $this->externalTaskRepository->findByAssembly($assembly->getId());

            foreach ($tasks as $task) {
                $currentDate = $task->getDay()->getDate();
                $targetDate  = $this->assemblyService->resolveAssemblyDate($currentDate, $assembly);

                if ($currentDate->format('Y-m-d') === $targetDate->format('Y-m-d')) {
                    $skipped++;
                    continue;
                }

                $targetDay = $this->dayRepository->findByAssemblyAndDate($assembly->getId(), $targetDate);
                if ($targetDay === null) {
                    $targetDay = new Day();
                    $targetDay->setAssembly($assembly);
                    $targetDay->setDate($targetDate);
                    $this->entityManager->persist($targetDay);
                    $this->entityManager->flush();
                }

                $task->setDay($targetDay);
                $moved++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('%d Aufgabe(n) korrigiert, %d unverändert.', $moved, $skipped));

        return Command::SUCCESS;
    }
}
