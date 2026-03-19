<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Person;

/**
 * Parst ICS-Inhalt und gleicht SUMMARY-Felder mit Personennamen ab.
 *
 * Gibt strukturierte Ergebnisse zurück, ohne Datenbankoperationen.
 * Wird von AbsenceController und ExternalTaskController für den Review-Flow verwendet.
 */
class IcsMatcherService
{
    /**
     * Parst ICS-Inhalt und versucht, jeden Event einer Person zuzuordnen.
     *
     * Rückgabe: Array von Items mit match_type 'exact', 'partial' oder 'none'.
     *
     * @param Person[] $persons
     * @return array<int, array{date_start: string, date_end: string, summary: string, description: string, person_id: int|null, match_type: string, candidates: list<array{id: int, name: string}>}>
     */
    public function parseAndMatch(string $icsContent, array $persons): array
    {
        $events = $this->parseIcs($icsContent);

        $exactMap = [];
        foreach ($persons as $person) {
            $exactMap[$this->normalize($person->getName())] = $person;
        }

        $items = [];
        foreach ($events as $event) {
            $summary     = $event['summary'] ?? '';
            $description = $event['description'] ?? $summary;
            $dateStart   = $event['dtstart'] ?? null;
            $dateEnd     = $event['dtend'] ?? $dateStart;

            if ($dateStart === null || $summary === '') {
                continue;
            }

            $normalized = $this->normalize($summary);
            $personId   = null;
            $matchType  = 'none';
            $candidates = [];

            if (isset($exactMap[$normalized])) {
                $personId  = $exactMap[$normalized]->getId();
                $matchType = 'exact';
            } else {
                $found = [];
                foreach ($persons as $person) {
                    $pNorm = $this->normalize($person->getName());
                    if (str_contains($normalized, $pNorm) || str_contains($pNorm, $normalized)) {
                        $found[] = $person;
                    } else {
                        // Token match: at least one name token must appear in the summary
                        $tokens = preg_split('/[\s,]+/', $pNorm);
                        foreach ($tokens as $token) {
                            if (strlen($token) >= 3 && str_contains($normalized, $token)) {
                                $found[] = $person;
                                break;
                            }
                        }
                    }
                }

                $found = array_unique($found, SORT_REGULAR);

                if (count($found) === 1) {
                    $personId  = $found[0]->getId();
                    $matchType = 'partial';
                } elseif (count($found) > 1) {
                    $matchType  = 'partial';
                    $personId   = $found[0]->getId();
                    $candidates = array_map(
                        fn(Person $p) => ['id' => $p->getId(), 'name' => $p->getName()],
                        $found,
                    );
                }
            }

            $items[] = [
                'date_start'  => $dateStart->format('Y-m-d'),
                'date_end'    => $dateEnd->format('Y-m-d'),
                'summary'     => $summary,
                'description' => $description,
                'person_id'   => $personId,
                'match_type'  => $matchType,
                'candidates'  => $candidates,
            ];
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    public function parseIcs(string $content): array
    {
        $events  = [];
        $current = null;

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $unfolded = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
                if (!empty($unfolded)) {
                    $unfolded[count($unfolded) - 1] .= ltrim($line);
                }
            } else {
                $unfolded[] = $line;
            }
        }

        foreach ($unfolded as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = [];
                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($current !== null) {
                    $events[] = $current;
                }
                $current = null;
                continue;
            }
            if ($current === null) {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $key     = strtoupper(substr($line, 0, $colonPos));
            $value   = substr($line, $colonPos + 1);
            $semiPos = strpos($key, ';');
            $baseKey = $semiPos !== false ? substr($key, 0, $semiPos) : $key;
            $params  = $semiPos !== false ? substr($key, $semiPos + 1) : '';

            if ($baseKey === 'DTSTART' || $baseKey === 'DTEND') {
                $date = $this->parseDate($value, $params);
                if ($date !== null) {
                    $current[strtolower($baseKey)] = $date;
                }
            } elseif ($baseKey === 'SUMMARY') {
                $current['summary'] = $this->unescape($value);
            } elseif ($baseKey === 'DESCRIPTION') {
                $current['description'] = $this->unescape($value);
            }
        }

        return $events;
    }

    /**
     * Normalisiert einen String für den Vergleich: Diakritika entfernen, Kleinschreibung.
     *
     * Unterstützt u.a. türkisches İ/ı, é, ü, ö, ä, ß usw.
     */
    private function normalize(string $str): string
    {
        if (function_exists('transliterator_transliterate')) {
            $result = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $str);
            if ($result !== false) {
                return $result;
            }
        }

        // Sonderfall: türkisches İ muss vor mb_strtolower behandelt werden
        $str = str_replace('İ', 'i', $str);
        $str = mb_strtolower($str, 'UTF-8');

        return strtr($str, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ı' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
            'ś' => 's', 'š' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
            'ć' => 'c', 'č' => 'c', 'ł' => 'l', 'ń' => 'n',
            'ř' => 'r', 'ď' => 'd', 'ť' => 't',
        ]);
    }

    private function parseDate(string $value, string $params): ?\DateTimeImmutable
    {
        $value = trim($value);
        try {
            if (strlen($value) === 8 && ctype_digit($value)) {
                return new \DateTimeImmutable($value);
            }
            if (str_ends_with($value, 'Z')) {
                return new \DateTimeImmutable(substr($value, 0, 8));
            }
            if (strlen($value) >= 15 && $value[8] === 'T') {
                return new \DateTimeImmutable(substr($value, 0, 8));
            }
        } catch (\Exception) {
            return null;
        }
        return null;
    }

    private function unescape(string $value): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;'], ["\n", "\n", ',', ';'], $value);
    }
}
