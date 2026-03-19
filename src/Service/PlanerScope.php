<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * Ermittelt den Daten-Scope für Benutzer mit ROLE_PLANER.
 *
 * Analogon zu AssemblyContext — kapselt die Scope-Ermittlung auf
 * Abteilungsebene. Wird nur aktiviert wenn der Benutzer genau
 * ROLE_PLANER hat, nicht ROLE_ASSEMBLY_ADMIN oder ROLE_ADMIN.
 *
 * Alle Controller und Repositories verwenden ausschließlich diesen
 * Service um Department-IDs zu ermitteln — keine inline Aufrufe von
 * $user->getDepartments() im Controller-Code.
 */
class PlanerScope
{
    /**
     * Gibt die Department-IDs zurück, auf die der Planer Zugriff hat.
     *
     * Gibt ein leeres Array zurück wenn dem Planer noch keine Abteilungen
     * zugeordnet sind — kein Fehler, kein Fallback.
     *
     * @return int[]
     */
    public function getDepartmentIds(User $user): array
    {
        return $user->getDepartments()->map(fn($d) => $d->getId())->toArray();
    }

    /**
     * Gibt true zurück wenn der Planer-Scope aktiv ist.
     *
     * Nur bei genau ROLE_PLANER aktiv — ROLE_ASSEMBLY_ADMIN und ROLE_ADMIN
     * erhalten keinen Department-Filter, sie sehen immer die gesamte
     * Versammlung.
     */
    public function isActive(User $user): bool
    {
        return $user->getRole() === 'ROLE_PLANER';
    }
}
