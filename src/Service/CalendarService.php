<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Assignment;
use App\Entity\ExternalTask;
use App\Entity\Person;
use App\Entity\SpecialDate;
use App\Entity\User;
use App\Enum\SpecialDateType;
use App\Repository\AbsenceRepository;
use App\Repository\AssignmentRepository;
use App\Repository\ExternalTaskRepository;
use App\Repository\SpecialDateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lädt Kalendereinträge für einen Benutzer und formatiert sie als FullCalendar-Objekte.
 *
 * Abwesenheiten werden rollenabhängig geladen: Planer sehen nur die Abwesenheiten
 * von Personen aus ihren zugeordneten Abteilungen; alle anderen Rollen sehen die
 * gesamte Versammlung.
 */
class CalendarService
{
    public function __construct(
        private readonly AbsenceRepository $absenceRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly ExternalTaskRepository $externalTaskRepository,
        private readonly SpecialDateRepository $specialDateRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AssemblyContext $assemblyContext,
        private readonly PlanerScope $planerScope,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Gibt alle Kalendereinträge für den Benutzer im angegebenen Zeitraum zurück.
     *
     * ROLE_ASSEMBLY_ADMIN sieht alle Zuteilungen und externen Aufgaben der Versammlung.
     * Alle anderen sehen nur ihre eigenen Einträge (über verknüpfte Person).
     * Abwesenheiten werden rollenabhängig per loadAbsencesForUser gefiltert.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEventsForUser(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        try {
            return $this->buildEvents($user, $from, $to);
        } catch (\Throwable $e) {
            $this->logger->error('Kalender-Events konnten nicht geladen werden.', [
                'user'      => $user->getUserIdentifier(),
                'from'      => $from->format('Y-m-d'),
                'to'        => $to->format('Y-m-d'),
                'exception' => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function buildEvents(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $events = [];

        $absences = $this->loadAbsencesForUser($user, $from, $to);
        foreach ($absences as $absence) {
            $events[] = $this->formatAbsenceEvent($absence, $this->userOwnsAbsence($user, $absence));
        }

        $assembly = $this->assemblyContext->getActiveAssembly($user);
        $roles    = $user->getRoles();
        $seesAll  = in_array('ROLE_ASSEMBLY_ADMIN', $roles, true) || in_array('ROLE_ADMIN', $roles, true);

        if ($assembly !== null && $seesAll) {
            $assignments = $this->assignmentRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to);
            foreach ($assignments as $assignment) {
                $isOwn    = $user->getPerson() !== null && $assignment->getPerson()->getId() === $user->getPerson()->getId();
                $events[] = $this->formatAssignmentEvent($assignment, $isOwn);
            }

            $externalTasks = $this->externalTaskRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to);
            foreach ($externalTasks as $et) {
                $isOwn    = $user->getPerson() !== null && $et->getPerson()->getId() === $user->getPerson()->getId();
                $events[] = $this->formatExternalTaskEvent($et, $isOwn);
            }
        } else {
            $person = $user->getPerson();
            if ($person !== null) {
                $assignments = $this->assignmentRepository->findByPersonAndPeriod($person->getId(), $from, $to);
                foreach ($assignments as $assignment) {
                    $events[] = $this->formatAssignmentEvent($assignment, true);
                }

                $externalTasks = $this->externalTaskRepository->findByPersonAndPeriod($person->getId(), $from, $to);
                foreach ($externalTasks as $et) {
                    $events[] = $this->formatExternalTaskEvent($et, true);
                }
            }
        }

        if ($assembly !== null) {
            $specialDates = $this->specialDateRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to);
            foreach ($specialDates as $sd) {
                $events[] = $this->formatSpecialDateEvent($sd);
            }
        }

        $this->logger->info('Kalender-Events geladen.', [
            'user'   => $user->getUserIdentifier(),
            'count'  => count($events),
            'from'   => $from->format('Y-m-d'),
            'to'     => $to->format('Y-m-d'),
            'seesAll' => $seesAll,
        ]);

        return $events;
    }

    /** Legt eine neue Abwesenheit an und speichert sie in der Datenbank. */
    public function createAbsence(
        Person $person,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        ?string $note,
    ): Absence {
        $absence = new Absence();
        $absence->setPerson($person);
        $absence->setStartDate($startDate);
        $absence->setEndDate($endDate);
        $absence->setNote($note);

        $this->entityManager->persist($absence);
        $this->entityManager->flush();

        return $absence;
    }

    /** Aktualisiert Zeitraum und Notiz einer bestehenden Abwesenheit. */
    public function updateAbsence(
        Absence $absence,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        ?string $note,
    ): void {
        $absence->setStartDate($startDate);
        $absence->setEndDate($endDate);
        $absence->setNote($note);

        $this->entityManager->flush();
    }

    /** Löscht eine Abwesenheit aus der Datenbank. */
    public function deleteAbsence(Absence $absence): void
    {
        $this->entityManager->remove($absence);
        $this->entityManager->flush();
    }

    /** Gibt true zurück, wenn die Abwesenheit zur verknüpften Person des Benutzers gehört. */
    public function userOwnsAbsence(User $user, Absence $absence): bool
    {
        if ($user->getPerson() === null) {
            return false;
        }

        return $absence->getPerson()->getId() === $user->getPerson()->getId();
    }

    /** @return Absence[] */
    private function loadAbsencesForUser(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $activeAssembly = $this->assemblyContext->getActiveAssembly($user);

        if ($activeAssembly === null) {
            return [];
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->absenceRepository->findByAssemblyAndPeriod(
                $activeAssembly->getId(),
                $from,
                $to
            );
        }

        if ($this->planerScope->isActive($user)) {
            $departmentIds = $this->planerScope->getDepartmentIds($user);
            return $this->absenceRepository->findByDepartmentsAndPeriod($departmentIds, $from, $to);
        }

        return $this->absenceRepository->findByAssemblyAndPeriod(
            $activeAssembly->getId(),
            $from,
            $to
        );
    }

    /** @return array<string, mixed> */
    private function formatAbsenceEvent(Absence $absence, bool $isOwn): array
    {
        return [
            'id'              => 'absence-' . $absence->getId(),
            'title'           => '🚫 ' . $absence->getPerson()->getName(),
            'start'           => $absence->getStartDate()->format('Y-m-d'),
            'end'             => $absence->getEndDate()->modify('+1 day')->format('Y-m-d'),
            'order'           => 2,
            'backgroundColor' => $isOwn ? '#0d6efd' : '#6c757d',
            'borderColor'     => $isOwn ? '#0a58ca' : '#5a6268',
            'extendedProps'   => [
                'type'       => 'absence',
                'personName' => $absence->getPerson()->getName(),
                'note'       => $absence->getNote(),
                'isOwn'      => $isOwn,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function formatAssignmentEvent(Assignment $assignment, bool $isOwn): array
    {
        $deptColor = $assignment->getTask()->getDepartment()->getColor();
        $title     = $isOwn
            ? $assignment->getTask()->getName()
            : $assignment->getTask()->getName() . "\n" . $assignment->getPerson()->getName();

        return [
            'id'              => 'assignment-' . $assignment->getId(),
            'title'           => $title,
            'start'           => $assignment->getDay()->getDate()->format('Y-m-d'),
            'allDay'          => true,
            'order'           => $isOwn ? 1 : 3,
            'backgroundColor' => $isOwn ? '#ffffff' : $deptColor,
            'borderColor'     => $deptColor,
            'textColor'       => $isOwn ? '#000000' : $this->contrastColor($deptColor),
            'extendedProps'   => [
                'type'      => 'assignment',
                'taskName'  => $assignment->getTask()->getName(),
                'deptColor' => $deptColor,
                'isOwn'     => $isOwn,
            ],
        ];
    }

    /** Gibt #000000 oder #ffffff zurück je nach Helligkeit des Hex-Farbwerts. */
    private function contrastColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $r   = hexdec(substr($hex, 0, 2));
        $g   = hexdec(substr($hex, 2, 2));
        $b   = hexdec(substr($hex, 4, 2));
        return (($r * 299 + $g * 587 + $b * 114) / 1000) >= 128 ? '#000000' : '#ffffff';
    }

    /** @return array<string, mixed> */
    private function formatExternalTaskEvent(ExternalTask $et, bool $isOwn): array
    {
        $title = $isOwn
            ? ($et->getDescription() ?? 'Andere Aufgabe')
            : $et->getPerson()->getName() . ': ' . ($et->getDescription() ?? 'Andere Aufgabe');

        return [
            'id'              => 'external-' . $et->getId(),
            'title'           => $title,
            'start'           => $et->getDay()->getDate()->format('Y-m-d'),
            'allDay'          => true,
            'backgroundColor' => $isOwn ? '#fd7e14' : '#adb5bd',
            'borderColor'     => $isOwn ? '#dc6502' : '#868e96',
            'extendedProps'   => [
                'type'  => 'external_task',
                'isOwn' => $isOwn,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function formatSpecialDateEvent(SpecialDate $sd): array
    {
        $label = match ($sd->getTypeEnum()) {
            SpecialDateType::CONGRESS     => 'Kongress',
            SpecialDateType::MEMORIAL     => 'Gedächtnisfeier',
            SpecialDateType::SERVICE_WEEK => 'Dienstwoche',
            SpecialDateType::MISC         => 'Sonstiges',
        };

        // Congress spans the full calendar week (Mon–Sun) in the calendar view
        if ($sd->getTypeEnum() === SpecialDateType::CONGRESS) {
            $startDate = $this->getMondayOfWeek($sd->getStartDate());
            $endDate   = $startDate->modify('+7 days'); // exklusives Ende für FullCalendar
        } else {
            $startDate = $sd->getStartDate();
            $endDate   = $sd->getEndDate()->modify('+1 day');
        }

        return [
            'id'              => 'special-' . $sd->getId(),
            'title'           => $label . ($sd->getNote() ? ': ' . $sd->getNote() : ''),
            'start'           => $startDate->format('Y-m-d'),
            'end'             => $endDate->format('Y-m-d'),
            'allDay'          => true,
            'backgroundColor' => '#6f42c1',
            'borderColor'     => '#59359a',
            'extendedProps'   => [
                'type'  => 'special_date',
                'isOwn' => false,
            ],
        ];
    }

    private function getMondayOfWeek(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $dow = (int) $date->format('N'); // 1=Mon, 7=Sun
        return $date->modify('-' . ($dow - 1) . ' days');
    }
}
