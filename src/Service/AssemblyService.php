<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assembly;
use Doctrine\ORM\EntityManagerInterface;

/** Persistiert Versammlungen und stellt Hilfsmethoden für die Versammlungslogik bereit. */
class AssemblyService
{
    /** Wochentags-Werte Mo–Fr gemäß AssemblyType-Konvention (1=Mo … 5=Fr). */
    private const WEEKDAYS = [1, 2, 3, 4, 5];

    /** Wochenend-Werte Sa/So gemäß AssemblyType-Konvention (6=Sa, 0=So). */
    private const WEEKEND_DAYS = [6, 0];

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function save(Assembly $assembly): void
    {
        $this->entityManager->persist($assembly);
        $this->entityManager->flush();
    }

    public function delete(Assembly $assembly): void
    {
        $this->entityManager->remove($assembly);
        $this->entityManager->flush();
    }

    /**
     * Mappt ein importiertes Datum auf den konfigurierten Versammlungstag der Woche.
     *
     * Liegt das Datum auf einem Wochentag (Mo–Fr), wird der konfigurierte Versammlungs-
     * Wochentag derselben ISO-Woche zurückgegeben. Liegt es auf einem Wochenendtag (Sa/So),
     * wird der konfigurierte Versammlungs-Wochenendtag derselben ISO-Woche zurückgegeben.
     *
     * Voraussetzung: Die Versammlung hat genau einen Wochentags- und einen Wochenendstag
     * konfiguriert (wird durch das Formular sichergestellt).
     */
    public function resolveAssemblyDate(\DateTimeImmutable $importDate, Assembly $assembly): \DateTimeImmutable
    {
        $weekdays  = $assembly->getWeekdays();
        $isoDow    = (int) $importDate->format('N'); // 1=Mo … 7=So
        $isWeekend = $isoDow >= 6;

        $targetDow = null;
        foreach ($weekdays as $day) {
            if (in_array($day, self::WEEKEND_DAYS, true) === $isWeekend) {
                $targetDow = $day;
                break;
            }
        }

        if ($targetDow === null) {
            return $importDate;
        }

        // 0=So → ISO 7, alle anderen Werte bleiben (1–6)
        $targetIsoDow = $targetDow === 0 ? 7 : $targetDow;

        $monday = $importDate->modify('Monday this week');

        return $monday->modify(sprintf('+%d days', $targetIsoDow - 1));
    }

    /**
     * Prüft ob die Wochentags-Konfiguration gültig ist:
     * genau 2 Tage, einer Mo–Fr, einer Sa/So.
     */
    public static function validateWeekdays(array $weekdays): bool
    {
        if (count($weekdays) !== 2) {
            return false;
        }

        $hasWeekday = false;
        $hasWeekend = false;

        foreach ($weekdays as $day) {
            if (in_array($day, self::WEEKDAYS, true)) {
                $hasWeekday = true;
            } elseif (in_array($day, self::WEEKEND_DAYS, true)) {
                $hasWeekend = true;
            }
        }

        return $hasWeekday && $hasWeekend;
    }
}
