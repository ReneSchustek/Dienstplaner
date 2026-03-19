<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Benutzerrollen im System.
 *
 * Hierarchie (security.yaml):
 * ROLE_ADMIN > ROLE_ASSEMBLY_ADMIN > ROLE_PLANER > ROLE_USER
 */
enum UserRole: string
{
    case Admin = 'ROLE_ADMIN';
    case AssemblyAdmin = 'ROLE_ASSEMBLY_ADMIN';
    case Planer = 'ROLE_PLANER';
    case User = 'ROLE_USER';
    case Guest = 'ROLE_GUEST';

    public function label(): string
    {
        return match($this) {
            self::Admin => 'Admin',
            self::AssemblyAdmin => 'Versammlungsadmin',
            self::Planer => 'Planer',
            self::User => 'Benutzer',
            self::Guest => 'Gast',
        };
    }
}
