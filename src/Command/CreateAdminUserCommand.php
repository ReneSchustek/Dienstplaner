<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Service\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:create-admin', description: 'Erstellt einen Admin-Benutzer')]
/**
 * CLI-Befehl zum Anlegen eines initialen Admin-Benutzers.
 *
 * Verwendung: php bin/console app:create-admin-user
 */
class CreateAdminUserCommand extends Command
{
    public function __construct(private readonly UserService $userService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $io->ask('E-Mail-Adresse');
        $password = $io->askHidden('Passwort');

        $user = new User();
        $user->setEmail($email);
        $user->setRole(UserRole::Admin->value);

        $this->userService->createUser($user, $password);

        $io->success('Admin-Benutzer wurde angelegt: ' . $email);

        return Command::SUCCESS;
    }
}
