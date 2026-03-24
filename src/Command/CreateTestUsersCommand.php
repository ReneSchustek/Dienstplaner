<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\AssemblyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Erstellt Testbenutzer für alle Rollen (außer ROLE_ADMIN).
 *
 * Verwendung: php bin/console app:create-test-users <assembly-id>
 */
#[AsCommand(
    name: 'app:create-test-users',
    description: 'Erstellt Testbenutzer für alle Rollen in einer Versammlung.',
)]
class CreateTestUsersCommand extends Command
{
    public function __construct(
        private readonly AssemblyRepository $assemblyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('assembly-id', InputArgument::REQUIRED, 'ID der Versammlung');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $assemblyId = (int) $input->getArgument('assembly-id');
        $assembly   = $this->assemblyRepository->find($assemblyId);

        if ($assembly === null) {
            $io->error("Versammlung mit ID $assemblyId nicht gefunden.");
            return Command::FAILURE;
        }

        $io->title('Testbenutzer erstellen für: ' . $assembly->getName());

        $testUsers = [
            ['email' => 'test.user@example.com',           'firstName' => 'Test', 'lastName' => 'Benutzer',        'role' => 'ROLE_USER'],
            ['email' => 'test.planer@example.com',          'firstName' => 'Test', 'lastName' => 'Planer',           'role' => 'ROLE_PLANER'],
            ['email' => 'test.assembly-admin@example.com',  'firstName' => 'Test', 'lastName' => 'Versammlungsadmin', 'role' => 'ROLE_ASSEMBLY_ADMIN'],
        ];

        $rows = [];
        foreach ($testUsers as $data) {
            $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existing !== null) {
                $io->warning("Benutzer {$data['email']} existiert bereits – übersprungen.");
                continue;
            }

            $password = bin2hex(random_bytes(8));

            $user = new User();
            $user->setEmail($data['email']);
            $user->setFirstName($data['firstName']);
            $user->setLastName($data['lastName']);
            $user->setRole($data['role']);
            $user->setAssembly($assembly);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $user->setForcePasswordChange(true);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $rows[] = [$data['email'], $data['firstName'] . ' ' . $data['lastName'], $data['role'], $password];
        }

        if (!empty($rows)) {
            $io->table(['E-Mail', 'Name', 'Rolle', 'Passwort'], $rows);
            $io->success('Testbenutzer wurden erstellt. Passwörter bitte notieren – sie werden nicht erneut angezeigt.');
        } else {
            $io->info('Keine neuen Benutzer erstellt.');
        }

        return Command::SUCCESS;
    }
}
