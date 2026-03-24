<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig-Filter für Farbberechnungen und Namensformatierung.
 *
 * contrast_color: Gibt '#000000' oder '#ffffff' zurück, je nachdem
 * welche Textfarbe auf dem angegebenen Hintergrund besser lesbar ist.
 *
 * first_name: Gibt den ersten Teil eines vollständigen Namens zurück.
 */
class ColorExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('contrast_color', [$this, 'contrastColor']),
            new TwigFilter('first_name', [$this, 'firstName']),
        ];
    }

    /** Gibt den Vornamen zurück. Unterstützt "Nachname, Vorname" und "Vorname Nachname". */
    public function firstName(string $fullName): string
    {
        $trimmed = trim($fullName);

        if (str_contains($trimmed, ', ')) {
            return explode(', ', $trimmed, 2)[1];
        }

        return explode(' ', $trimmed, 2)[0];
    }

    public function contrastColor(string $hexColor): string
    {
        $hex = ltrim($hexColor, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.5 ? '#000000' : '#ffffff';
    }
}
