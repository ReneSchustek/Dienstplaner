<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assembly;
use App\Entity\User;
use App\Repository\AssemblyRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Ermittelt die aktive Versammlung eines Benutzers.
 *
 * Administratoren können per Session in eine fremde Versammlung wechseln.
 * Alle anderen Benutzer sehen immer ihre eigene Versammlung.
 */
class AssemblyContext
{
    private const SESSION_KEY = '_admin_assembly_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly AssemblyRepository $assemblyRepository,
    ) {}

    public function getActiveAssembly(User $user): ?Assembly
    {
        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $user->getAssembly();
        }

        $session = $this->requestStack->getSession();
        $assemblyId = $session->get(self::SESSION_KEY);

        if ($assemblyId !== null) {
            $assembly = $this->assemblyRepository->find($assemblyId);
            if ($assembly !== null) {
                return $assembly;
            }
        }

        return $user->getAssembly();
    }

    public function setActiveAssembly(int $assemblyId): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $assemblyId);
    }

    public function resetToOwnAssembly(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }
}
