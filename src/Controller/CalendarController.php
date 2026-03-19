<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Absence;
use App\Entity\User;
use App\Repository\AbsenceRepository;
use App\Service\CalendarService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Abwesenheitskalender für angemeldete Benutzer.
 *
 * Liefert Abwesenheiten als FullCalendar-kompatible JSON-Ereignisse
 * und ermöglicht CRUD-Operationen auf eigene Abwesenheiten.
 */
#[Route('/calendar')]
#[IsGranted('ROLE_USER')]
class CalendarController extends AbstractController
{
    public function __construct(
        private readonly CalendarService $calendarService,
        private readonly AbsenceRepository $absenceRepository,
    ) {}

    #[Route('', name: 'calendar_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('calendar/index.html.twig');
    }

    #[Route('/events', name: 'calendar_events', methods: ['GET'])]
    public function events(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Das + im Timezone-Offset (z.B. +01:00) wird in Query-Strings als Leerzeichen dekodiert.
        $from = new \DateTimeImmutable(str_replace(' ', '+', $request->query->getString('start', 'today')));
        $to   = new \DateTimeImmutable(str_replace(' ', '+', $request->query->getString('end', 'today')));

        $events = $this->calendarService->getEventsForUser($user, $from, $to);

        return $this->json($events);
    }

    #[Route('/absences', name: 'calendar_absence_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getPerson() === null) {
            return $this->json(['error' => 'Kein Personenprofil verknüpft.'], 403);
        }

        $data = $request->toArray();

        if (!$this->isCsrfTokenValid('calendar', $data['_token'] ?? '')) {
            return $this->json(['error' => 'Ungültiges CSRF-Token.'], 403);
        }

        try {
            $startDate = new \DateTimeImmutable($data['startDate'] ?? '');
            $endDate = new \DateTimeImmutable($data['endDate'] ?? '');
        } catch (\Exception) {
            return $this->json(['error' => 'Ungültiges Datum.'], 400);
        }

        $absence = $this->calendarService->createAbsence(
            $user->getPerson(),
            $startDate,
            $endDate,
            $data['note'] ?? null,
        );

        return $this->json(['id' => $absence->getId()], 201);
    }

    #[Route('/absences/{id}', name: 'calendar_absence_show', methods: ['GET'])]
    public function show(Absence $absence): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->calendarService->userOwnsAbsence($user, $absence)) {
            return $this->json(['error' => 'Kein Zugriff.'], 403);
        }

        return $this->json([
            'id' => $absence->getId(),
            'startDate' => $absence->getStartDate()->format('Y-m-d'),
            'endDate' => $absence->getEndDate()->format('Y-m-d'),
            'note' => $absence->getNote(),
        ]);
    }

    #[Route('/absences/{id}', name: 'calendar_absence_update', methods: ['PUT'])]
    public function update(Request $request, Absence $absence): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->calendarService->userOwnsAbsence($user, $absence)) {
            return $this->json(['error' => 'Kein Zugriff.'], 403);
        }

        $data = $request->toArray();

        if (!$this->isCsrfTokenValid('calendar', $data['_token'] ?? '')) {
            return $this->json(['error' => 'Ungültiges CSRF-Token.'], 403);
        }

        try {
            $startDate = new \DateTimeImmutable($data['startDate'] ?? '');
            $endDate = new \DateTimeImmutable($data['endDate'] ?? '');
        } catch (\Exception) {
            return $this->json(['error' => 'Ungültiges Datum.'], 400);
        }

        $this->calendarService->updateAbsence($absence, $startDate, $endDate, $data['note'] ?? null);

        return $this->json(['success' => true]);
    }

    #[Route('/absences/{id}', name: 'calendar_absence_delete', methods: ['DELETE'])]
    public function delete(Request $request, Absence $absence): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->calendarService->userOwnsAbsence($user, $absence)) {
            return $this->json(['error' => 'Kein Zugriff.'], 403);
        }

        $data = $request->toArray();

        if (!$this->isCsrfTokenValid('calendar', $data['_token'] ?? '')) {
            return $this->json(['error' => 'Ungültiges CSRF-Token.'], 403);
        }

        $this->calendarService->deleteAbsence($absence);

        return $this->json(['success' => true]);
    }
}
