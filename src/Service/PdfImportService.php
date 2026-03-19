<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Person;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser;

/**
 * Extrahiert Text aus PDF-Dateien und gleicht Zeilen mit Personennamen und Datumsangaben ab.
 *
 * Unterstützt zwei Formate:
 * 1. Zeilenformat: jede Zeile enthält Datum + Personenname (dd.mm.yyyy)
 * 2. Wochenprogramm-Format (LuDz): Wochenblöcke mit Datumskopf und Personenzuweisungen
 */
class PdfImportService
{
    public function __construct(private readonly LoggerInterface $logger) {}

    private const MONTHS_DE = [
        'JANUAR' => 1, 'FEBRUAR' => 2, 'MÄRZ' => 3, 'APRIL' => 4,
        'MAI' => 5, 'JUNI' => 6, 'JULI' => 7, 'AUGUST' => 8,
        'SEPTEMBER' => 9, 'OKTOBER' => 10, 'NOVEMBER' => 11, 'DEZEMBER' => 12,
    ];

    /**
     * @param Person[] $persons
     * @return array<int, array{date: string, summary: string, description: string, person_id: int|null, match_type: string}>
     */
    public function parseAndMatch(string $pdfPath, array $persons): array
    {
        $parser = new Parser();
        $pdf    = $parser->parseFile($pdfPath);
        $text   = $pdf->getText();

        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = array_values(array_filter(array_map('trim', $lines), fn(string $l) => $l !== ''));

        $this->logger->info('PDF-Import gestartet.', [
            'file'    => basename($pdfPath),
            'lines'   => count($lines),
            'persons' => count($persons),
        ]);

        $items = $this->parseStandardFormat($lines, $persons);
        if (!empty($items)) {
            $this->logger->info('PDF-Import: Standard-Format erkannt.', ['items' => count($items)]);
            return $items;
        }

        $items = $this->parseMeetingProgramFormat($lines, $persons);
        $this->logger->info('PDF-Import: Wochenprogramm-Format erkannt.', ['items' => count($items)]);

        return $items;
    }

    /**
     * Standard-Format: Zeilen mit dd.mm.yyyy + Personenname.
     *
     * @param Person[] $persons
     */
    private function parseStandardFormat(array $lines, array $persons): array
    {
        $exactMap = $this->buildExactMap($persons);
        $items    = [];

        foreach ($lines as $line) {
            $date = $this->extractStandardDate($line);
            if ($date === null) {
                continue;
            }

            $rest      = trim(preg_replace('/\d{1,2}[.\/\-]\d{1,2}[.\/\-]\d{2,4}/', '', $line));
            $rest      = trim(preg_replace('/\s{2,}/', ' ', $rest));
            $matchData = $this->matchPerson($rest, $persons, $exactMap);

            $items[] = [
                'date'        => $date,
                'summary'     => $line,
                'description' => $rest ?: $line,
                'person_id'   => $matchData['person_id'],
                'match_type'  => $matchData['match_type'],
            ];
        }

        return $items;
    }

    /**
     * Wochenprogramm-Format: Wochenblöcke mit Datumskopf und Einzelzuweisungen.
     *
     * Erkennt Zeilen wie:
     *   "6.-12. APRIL | JESAJA 50-51    Vorsitzender: E.Obermann"
     *   "19:06 1. Höre auf... (10 Min.)   P.Erler"
     *   "Teilnehmer/Partner:  N.Juskow/A.C.Lauterbach"
     *
     * @param Person[] $persons
     */
    private function parseMeetingProgramFormat(array $lines, array $persons): array
    {
        $exactMap = $this->buildExactMap($persons);
        $items    = [];

        $currentDate = null;
        $seenInWeek  = [];     // [personId => true] – verhindert Duplikate pro Woche
        $currentYear = (int) date('Y');

        foreach ($lines as $line) {
            $weekDate = $this->extractWeekStartDate($line, $currentYear);
            if ($weekDate !== null) {
                $currentDate = $weekDate;
                $seenInWeek  = [];

                if (preg_match('/Vorsitzender\s*:/u', $line)) {
                    $this->extractAndAddPersons($line, $currentDate, 'Vorsitzender', $persons, $exactMap, $seenInWeek, $items);
                }
                continue;
            }

            if ($currentDate === null) {
                continue;
            }

            if (preg_match('/Vorsitzender\s*:\s*(.+)/u', $line, $m)) {
                $this->extractAndAddPersons($line, $currentDate, 'Vorsitzender', $persons, $exactMap, $seenInWeek, $items);
                continue;
            }

            // Lines with explicit Teilnehmer/Partner/Leiter/Leser labels
            if (preg_match('/(?:Teilnehmer|Partner|Leiter|Leser)\s*(?:\/\s*(?:Partner|Leser))?\s*:\s*(.+)/u', $line, $m)) {
                $description = $this->extractTaskDescription($line);
                $this->extractAndAddPersons($line, $currentDate, $description, $persons, $exactMap, $seenInWeek, $items);
                continue;
            }

            // Timed assignment lines: "19:06 1. Description (N Min.)   PersonName"
            if (preg_match('/^\d{2}:\d{2}\s+\d+\.\s+.+\(\d+\s*Min\.?\)/u', $line)) {
                $description = $this->extractTaskDescription($line);
                $namesPart   = $this->extractNamesPartFromEnd($line);
                if ($namesPart !== '') {
                    $this->addPersonsFromText($namesPart, $currentDate, $description, $persons, $exactMap, $seenInWeek, $items);
                }
            }
        }

        return $items;
    }

    /**
     * Extrahiert Wochenstartdatum aus Zeilen wie "6.-12. APRIL | ..." oder "27. APRIL–3. MAI | ...".
     */
    private function extractWeekStartDate(string $line, int $year): ?string
    {
        $monthPattern = implode('|', array_keys(self::MONTHS_DE));

        // "6.-12. APRIL" or "6.–12. APRIL"
        if (preg_match('/^(\d{1,2})\.\s*[–\-]\s*\d{1,2}\.\s+(' . $monthPattern . ')/u', $line, $m)) {
            $month = self::MONTHS_DE[$m[2]];
            return sprintf('%04d-%02d-%02d', $year, $month, (int) $m[1]);
        }

        // "27. APRIL–3. MAI" – only the start month matters
        if (preg_match('/^(\d{1,2})\.\s+(' . $monthPattern . ')\s*[–\-]/u', $line, $m)) {
            $month = self::MONTHS_DE[$m[2]];
            return sprintf('%04d-%02d-%02d', $year, $month, (int) $m[1]);
        }

        return null;
    }

    /**
     * Extrahiert Personennamen aus einer Zeile und fügt Einträge zum $items-Array hinzu.
     *
     * @param array<int, true> $seenInWeek
     * @param array<int, array<string, mixed>> $items
     */
    private function extractAndAddPersons(
        string $line,
        string $date,
        string $description,
        array $persons,
        array $exactMap,
        array &$seenInWeek,
        array &$items,
    ): void {
        // Remove everything up to and including the last colon for labeled lines
        $namesPart = preg_replace('/^.*:\s*/u', '', $line);
        $this->addPersonsFromText($namesPart, $date, $description, $persons, $exactMap, $seenInWeek, $items);
    }

    /**
     * @param array<int, true> $seenInWeek
     * @param array<int, array<string, mixed>> $items
     */
    private function addPersonsFromText(
        string $text,
        string $date,
        string $description,
        array $persons,
        array $exactMap,
        array &$seenInWeek,
        array &$items,
    ): void {
        // Split by "/" for Teilnehmer/Partner assignments
        $nameParts = preg_split('/\s*\/\s*/', $text);

        foreach ($nameParts as $namePart) {
            $namePart = trim($namePart);
            if ($namePart === '') {
                continue;
            }

            $match    = $this->matchPerson($namePart, $persons, $exactMap);
            $personId = $match['person_id'];

            // Duplikate pro Woche nur für gematchte Personen prüfen
            if ($personId !== null) {
                if (isset($seenInWeek[$personId])) {
                    continue;
                }
                $seenInWeek[$personId] = true;
            }

            $items[] = [
                'date'        => $date,
                'summary'     => $namePart,
                'description' => $description,
                'person_id'   => $personId,
                'match_type'  => $match['match_type'],
            ];
        }
    }

    /**
     * Extrahiert die Aufgabenbeschreibung aus einer Zeile (ohne Zeit, Nummer, Personen).
     */
    private function extractTaskDescription(string $line): string
    {
        // Remove time prefix
        $line = preg_replace('/^\d{2}:\d{2}\s*/u', '', $line);
        // Remove item number
        $line = preg_replace('/^\d+\.\s*/u', '', $line);
        // Remove duration "(N Min.)"
        $line = preg_replace('/\(\d+\s*Min\.?\)/u', '', $line);
        // Remove person labels and names from end
        $line = preg_replace('/\s+(?:Teilnehmer|Partner|Leiter|Leser|Vorsitzender).*$/u', '', $line);
        // Remove trailing tabs/spaces and name-like content after tab
        $line = preg_replace('/\t.*$/u', '', $line);

        return trim($line);
    }

    /**
     * Extrahiert den Namensteil am Zeilenende (nach Tab-Trenner).
     */
    private function extractNamesPartFromEnd(string $line): string
    {
        // Names appear after a tab character
        if (str_contains($line, "\t")) {
            $parts = explode("\t", $line);
            return trim(end($parts));
        }

        // Or after multiple spaces
        if (preg_match('/\s{3,}(\S.*)$/u', $line, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /** @param Person[] $persons */
    private function buildExactMap(array $persons): array
    {
        $map = [];
        foreach ($persons as $person) {
            $map[$this->normalize($person->getName())] = $person;
        }
        return $map;
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

    /**
     * Erkennt "X.Lastname" oder "X.Y.Lastname"-Muster und gibt den ersten Anfangsbuchstaben zurück.
     *
     * Gibt null zurück, wenn das Muster nicht erkannt wurde.
     */
    private function extractFirstInitial(string $text): ?string
    {
        $text = trim($text);
        // Muster: ein oder mehrere "Buchstabe." Präfixe vor einem Nachnamensteil mit ≥2 Buchstaben
        if (preg_match('/^(\p{L}\.)(\p{L}\.)*\p{L}{2,}/u', $text)) {
            return mb_strtolower(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8');
        }
        return null;
    }

    /**
     * Prüft, ob mindestens ein Namenstoken der Person mit dem Anfangsbuchstaben beginnt.
     */
    private function personMatchesInitial(Person $person, string $normalizedInitial): bool
    {
        $tokens = preg_split('/[\s,]+/', $this->normalize($person->getName()));
        foreach ($tokens as $token) {
            if ($token !== '' && str_starts_with($token, $normalizedInitial)) {
                return true;
            }
        }
        return false;
    }

    private function extractStandardDate(string $line): ?string
    {
        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $line, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{2})\b/', $line, $m)) {
            $year = (int) $m[3] < 50 ? 2000 + (int) $m[3] : 1900 + (int) $m[3];
            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $line, $m)) {
            return $m[0];
        }
        return null;
    }

    /** @param Person[] $persons */
    private function matchPerson(string $text, array $persons, array $exactMap): array
    {
        $normalized = $this->normalize(trim($text));

        if (isset($exactMap[$normalized])) {
            return ['person_id' => $exactMap[$normalized]->getId(), 'match_type' => 'exact'];
        }

        // Anfangsbuchstaben-Constraint bei "X.Nachname"-Muster
        $firstInitial = $this->extractFirstInitial(trim($text));

        $found = [];
        foreach ($persons as $person) {
            if ($firstInitial !== null && !$this->personMatchesInitial($person, $firstInitial)) {
                continue;
            }

            $pNorm = $this->normalize($person->getName());
            if (str_contains($normalized, $pNorm) || str_contains($pNorm, $normalized)) {
                $found[] = $person;
                continue;
            }
            $tokens = preg_split('/[\s,]+/', $pNorm);
            foreach ($tokens as $token) {
                if (strlen($token) >= 3 && str_contains($normalized, $token)) {
                    $found[] = $person;
                    break;
                }
            }
        }

        $found = array_unique($found, SORT_REGULAR);

        if (count($found) >= 1) {
            return ['person_id' => $found[0]->getId(), 'match_type' => 'partial'];
        }

        return ['person_id' => null, 'match_type' => 'none'];
    }
}
