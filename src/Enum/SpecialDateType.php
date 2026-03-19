<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Typen besonderer Termine mit Auswirkung auf die Planung.
 *
 * - MEMORIAL: Gedächtnisfeier – Tag wird gesperrt
 * - CONGRESS: Kongress – Kongresswoche gesperrt, Vorwoche entfernt
 * - SERVICE_WEEK: Dienstwoche – Wochentags-Meetings auf Dienstag verschoben
 */
enum SpecialDateType: string
{
    case MEMORIAL = 'memorial';
    case CONGRESS = 'congress';
    case SERVICE_WEEK = 'service_week';
    case MISC = 'misc';

    public function label(): string
    {
        return match($this) {
            self::MEMORIAL     => 'special_date.type.memorial',
            self::CONGRESS     => 'special_date.type.congress',
            self::SERVICE_WEEK => 'special_date.type.service_week',
            self::MISC         => 'special_date.type.misc',
        };
    }

    public function planningLabel(): string
    {
        return match($this) {
            self::MEMORIAL     => 'planning.label.memorial',
            self::CONGRESS     => 'planning.label.congress',
            self::SERVICE_WEEK => 'planning.label.service_week',
            self::MISC         => 'planning.label.misc',
        };
    }
}
