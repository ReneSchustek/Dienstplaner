<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Assembly;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Prüft ob ein Benutzer eine bestimmte Assembly bearbeiten darf.
 *
 * ROLE_ADMIN darf jede Assembly bearbeiten.
 * ROLE_ASSEMBLY_ADMIN darf nur seine eigene Assembly bearbeiten.
 * Alle anderen Rollen erhalten ACCESS_DENIED.
 *
 * Verwendung: $this->denyAccessUnlessGranted('ASSEMBLY_EDIT', $assembly)
 */
class AssemblyVoter extends Voter
{
    public const EDIT = 'ASSEMBLY_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT && $subject instanceof Assembly;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Assembly $assembly */
        $assembly = $subject;

        if ($user->getRole() === 'ROLE_ADMIN') {
            return true;
        }

        if ($user->getRole() === 'ROLE_ASSEMBLY_ADMIN') {
            return $user->getAssembly()?->getId() === $assembly->getId();
        }

        return false;
    }
}
