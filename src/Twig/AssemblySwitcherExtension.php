<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\AssemblyRepository;
use App\Service\AssemblyContext;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig-Extension für den Versammlungs-Wechsler.
 *
 * Stellt Funktionen für Templates bereit, um die aktive Versammlung
 * und die verfügbaren Versammlungen (für Admins) abzufragen.
 */
class AssemblySwitcherExtension extends AbstractExtension
{
    public function __construct(
        private readonly AssemblyRepository $assemblyRepository,
        private readonly AssemblyContext $assemblyContext,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('all_assemblies', [$this, 'getAllAssemblies']),
            new TwigFunction('active_assembly_id', [$this, 'getActiveAssemblyId']),
        ];
    }

    public function getAllAssemblies(): array
    {
        return $this->assemblyRepository->findBy([], ['name' => 'ASC']);
    }

    public function getActiveAssemblyId(UserInterface $user): ?int
    {
        if (!$user instanceof User) {
            return null;
        }

        $assembly = $this->assemblyContext->getActiveAssembly($user);

        return $assembly?->getId();
    }
}
