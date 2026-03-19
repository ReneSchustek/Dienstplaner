<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assembly;
use App\Entity\Day;
use App\Entity\SpecialDate;
use App\Enum\SpecialDateType;
use App\Repository\DayRepository;
use App\Repository\SpecialDateRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class PlanningRuleService
{
    public function __construct(
        private readonly SpecialDateRepository $specialDateRepository,
        private readonly DayRepository $dayRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Applies special date rules to the day list.
     *
     * Returns array: [ $dayId => ['day' => Day, 'specialLabel' => string|null, 'isBlocked' => bool] ]
     * Days may be added or removed based on rules.
     */
    public function applyRules(Assembly $assembly, array $days, int $year, int $month): array
    {
        $from = new DateTimeImmutable("$year-$month-01");
        $to = new DateTimeImmutable($from->format('Y-m-t'));

        // Load special dates including 6 weeks before to catch congress affecting current month
        $lookbackFrom = $from->modify('-6 weeks');
        $specialDates = $this->specialDateRepository->findByAssemblyAndPeriod(
            $assembly->getId(),
            $lookbackFrom,
            $to,
        );

        $meta = [];
        foreach ($days as $day) {
            $meta[$day->getId()] = [
                'day' => $day,
                'specialLabel' => null,
                'isBlocked' => false,
            ];
        }

        foreach ($specialDates as $specialDate) {
            $meta = match ($specialDate->getTypeEnum()) {
                SpecialDateType::MEMORIAL     => $this->applyMemorial($meta, $specialDate),
                SpecialDateType::CONGRESS     => $this->applyCongress($meta, $specialDate),
                SpecialDateType::SERVICE_WEEK => $this->applyServiceWeek($assembly, $meta, $specialDate, $year, $month),
                SpecialDateType::MISC         => $this->applyMisc($meta, $specialDate),
            };
        }

        uasort($meta, fn($a, $b) => $a['day']->getDate() <=> $b['day']->getDate());

        return $meta;
    }

    /**
     * Markiert den Gedächtnisfeiertag als gesperrt.
     *
     * Der exakte Datumstag wird als blockiert markiert.
     */
    private function applyMemorial(array $meta, SpecialDate $specialDate): array
    {
        $dateStr = $specialDate->getStartDate()->format('Y-m-d');

        foreach ($meta as $dayId => $entry) {
            if ($entry['day']->getDate()->format('Y-m-d') === $dateStr) {
                $meta[$dayId]['specialLabel'] = SpecialDateType::MEMORIAL->planningLabel();
                $meta[$dayId]['isBlocked'] = true;
            }
        }

        return $meta;
    }

    /**
     * Wendet die Kongressregel an.
     *
     * Alle Planungstage in der Kalenderwoche des Kongress-Startdatums werden
     * als blockiert markiert (Donnerstag und Sonntag). Die Tage bleiben im
     * Planungsraster sichtbar und erhalten das Kongress-Label.
     */
    private function applyCongress(array $meta, SpecialDate $specialDate): array
    {
        $congressStart = $specialDate->getStartDate();

        $weekStart = $this->getMondayOfWeek($congressStart);
        $weekEnd   = $weekStart->modify('+6 days');

        foreach ($meta as $dayId => $entry) {
            $entryDate = $entry['day']->getDate();
            if ($entryDate >= $weekStart && $entryDate <= $weekEnd) {
                $meta[$dayId]['specialLabel'] = SpecialDateType::CONGRESS->planningLabel();
                $meta[$dayId]['isBlocked']    = true;
            }
        }

        return $meta;
    }

    /**
     * Wendet die Sonstiges-Regel an.
     *
     * Fällt das Datum auf ein Wochenende (Sa/So), werden die Wochenend-
     * Planungstage der gleichen Woche blockiert. Fällt das Datum auf einen
     * Wochentag (Mo–Fr), werden die Wochentags-Planungstage blockiert.
     */
    private function applyMisc(array $meta, SpecialDate $specialDate): array
    {
        $miscDate    = $specialDate->getStartDate();
        $miscWeekday = (int) $miscDate->format('w'); // 0=So, 6=Sa
        $isWeekend   = in_array($miscWeekday, [0, 6], true);

        $weekStart = $this->getMondayOfWeek($miscDate);
        $weekEnd   = $weekStart->modify('+6 days');

        foreach ($meta as $dayId => $entry) {
            $entryDate    = $entry['day']->getDate();
            if ($entryDate < $weekStart || $entryDate > $weekEnd) {
                continue;
            }

            $entryWeekday   = (int) $entryDate->format('w');
            $entryIsWeekend = in_array($entryWeekday, [0, 6], true);

            if ($isWeekend === $entryIsWeekend) {
                $meta[$dayId]['specialLabel'] = SpecialDateType::MISC->planningLabel();
                $meta[$dayId]['isBlocked']    = true;
            }
        }

        return $meta;
    }

    /**
     * Verschiebt Versammlungstage in einer Dienstwoche auf Dienstag.
     *
     * Für jede Woche innerhalb des Zeitraums: reguläre Wochentags-
     * Versammlungstage werden entfernt und durch Dienstag ersetzt.
     * Falls der Dienstag außerhalb des aktuellen Monats liegt, wird er
     * nicht hinzugefügt.
     */
    private function applyServiceWeek(Assembly $assembly, array $meta, SpecialDate $specialDate, int $year, int $month): array
    {
        $serviceStart = $specialDate->getStartDate();
        $serviceEnd = $specialDate->getEndDate();
        $currentMonday = $this->getMondayOfWeek($serviceStart);

        while ($currentMonday <= $serviceEnd) {
            $weekSunday = $currentMonday->modify('+6 days');

            $weekdayMeetings = $this->getWeekdayMeetingDaysInWeek($assembly, $currentMonday, $weekSunday);

            foreach ($weekdayMeetings as $meetingDate) {
                $meetingDateStr = $meetingDate->format('Y-m-d');

                foreach ($meta as $dayId => $entry) {
                    if ($entry['day']->getDate()->format('Y-m-d') === $meetingDateStr) {
                        unset($meta[$dayId]);
                        break;
                    }
                }

                $tuesday = $currentMonday->modify('+1 day');
                if ((int) $tuesday->format('Y') === $year && (int) $tuesday->format('n') === $month) {
                    $tuesdayDay = $this->getOrCreateDay($assembly, $tuesday);
                    if (!isset($meta[$tuesdayDay->getId()])) {
                        $meta[$tuesdayDay->getId()] = [
                            'day' => $tuesdayDay,
                            'specialLabel' => SpecialDateType::SERVICE_WEEK->planningLabel(),
                            'isBlocked' => false,
                        ];
                    }
                }
            }

            $currentMonday = $currentMonday->modify('+7 days');
        }

        return $meta;
    }

    /** Gibt den Montag der Woche zurück, in der das Datum liegt. */
    private function getMondayOfWeek(DateTimeImmutable $date): DateTimeImmutable
    {
        $dow = (int) $date->format('N'); // 1=Mon, 7=Sun
        return $date->modify('-' . ($dow - 1) . ' days');
    }

    /** @return DateTimeImmutable[] */
    private function getWeekdayMeetingDaysInWeek(Assembly $assembly, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $result = [];
        $current = $from;

        while ($current <= $to) {
            $weekday = (int) $current->format('w'); // 0=Sun, 6=Sat
            $isWeekday = !in_array($weekday, [0, 6], true);

            if ($isWeekday && in_array($weekday, $assembly->getWeekdays(), true)) {
                $result[] = $current;
            }

            $current = $current->modify('+1 day');
        }

        return $result;
    }

    /**
     * Lädt einen vorhandenen Planungstag oder legt ihn neu an.
     *
     * Wird benötigt, wenn die Dienstwochenregel einen Ersatztag (Dienstag)
     * in der Datenbank anlegen muss, der noch nicht existiert.
     */
    private function getOrCreateDay(Assembly $assembly, DateTimeImmutable $date): Day
    {
        $existing = $this->dayRepository->findByAssemblyAndDate($assembly->getId(), $date);
        if ($existing !== null) {
            return $existing;
        }

        $day = new Day();
        $day->setDate($date);
        $day->setAssembly($assembly);
        $this->entityManager->persist($day);
        $this->entityManager->flush();

        return $day;
    }
}
