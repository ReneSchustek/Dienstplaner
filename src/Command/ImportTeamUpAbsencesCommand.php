<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AssemblyRepository;
use App\Service\TeamUpImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:teamup:import', description: 'Importiert Abwesenheiten aus TeamUp')]
/**
 * CLI-Befehl zum Importieren von Abwesenheiten aus TeamUp.
 *
 * Verwendung: php bin/console app:import-teamup-absences {assemblyId}
 */
class ImportTeamUpAbsencesCommand extends Command
{
    public function __construct(
        private readonly TeamUpImportService $teamUpImportService,
        private readonly AssemblyRepository $assemblyRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $from = new \DateTimeImmutable('today');
        $to = $from->modify('+60 days');

        $assemblies = $this->assemblyRepository->findAll();
        $total = 0;

        foreach ($assemblies as $assembly) {
            $count = $this->teamUpImportService->importAbsencesForAssembly($assembly, $from, $to);
            $io->text(sprintf('  %s: %d Abwesenheiten importiert', $assembly->getName(), $count));
            $total += $count;
        }

        $io->success(sprintf('Import abgeschlossen. %d Abwesenheiten importiert.', $total));

        return Command::SUCCESS;
    }
}
