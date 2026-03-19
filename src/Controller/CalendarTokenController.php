<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Absence;
use App\Entity\Day;
use App\Entity\ExternalTask;
use App\Repository\AbsenceRepository;
use App\Repository\AssignmentRepository;
use App\Repository\DayRepository;
use App\Repository\ExternalTaskRepository;
use App\Repository\PersonRepository;
use App\Repository\SpecialDateRepository;
use App\Repository\UserRepository;
use App\Service\CalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Öffentlicher Kalenderzugriff über einen persönlichen Token.
 *
 * Benutzer können über einen geheimen Link ihren Kalender aufrufen,
 * Abwesenheiten und eigene externe Aufgaben verwalten sowie
 * geplante Aufgaben (Assignments) readonly einsehen.
 */
#[Route('/kalender')]
class CalendarTokenController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AbsenceRepository $absenceRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly ExternalTaskRepository $externalTaskRepository,
        private readonly SpecialDateRepository $specialDateRepository,
        private readonly DayRepository $dayRepository,
        private readonly PersonRepository $personRepository,
        private readonly CalendarService $calendarService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/{token}', name: 'calendar_token_view', methods: ['GET'])]
    public function view(string $token, Request $request): Response
    {
        $user = $this->userRepository->findByCalendarToken($token);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        $year  = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        $person        = $user->getPerson();
        $absences      = [];
        $externalTasks = [];
        $assignments   = [];
        $from          = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to            = new \DateTimeImmutable($from->format('Y-m-t'));

        if ($person !== null) {
            $absences      = $this->absenceRepository->findByPersonAndMonth($person->getId(), $year, $month);
            $externalTasks = $this->externalTaskRepository->findByPersonAndMonth($person->getId(), $year, $month);
            $assignments   = $this->assignmentRepository->findByPersonAndPeriod($person->getId(), $from, $to);
        }

        $assembly     = $user->getAssembly();
        $specialDates = $assembly !== null
            ? $this->specialDateRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to)
            : [];

        return $this->render('calendar/public.html.twig', [
            'user'          => $user,
            'person'        => $person,
            'absences'      => $absences,
            'externalTasks' => $externalTasks,
            'assignments'   => $assignments,
            'specialDates'  => $specialDates,
            'year'          => $year,
            'month'         => $month,
            'token'         => $token,
        ]);
    }

    #[Route('/{token}/absence/new', name: 'calendar_token_absence_new', methods: ['POST'])]
    public function newAbsence(string $token, Request $request): Response
    {
        $user = $this->userRepository->findByCalendarToken($token);
        if ($user === null || $user->getPerson() === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('calendar-token-absence-' . $token, $request->request->getString('_token'))) {
            return $this->redirectToRoute('calendar_token_view', ['token' => $token]);
        }

        try {
            $start = new \DateTimeImmutable($request->request->getString('start_date'));
            $end   = new \DateTimeImmutable($request->request->getString('end_date'));
        } catch (\Exception) {
            return $this->redirectToRoute('calendar_token_view', ['token' => $token]);
        }

        $note = $request->request->getString('note') ?: null;
        $this->calendarService->createAbsence($user->getPerson(), $start, $end, $note);

        return $this->redirectToRoute('calendar_token_view', ['token' => $token]);
    }

    #[Route('/{token}/absence/{id}/delete', name: 'calendar_token_absence_delete', methods: ['POST'])]
    public function deleteAbsence(string $token, int $id, Request $request): Response
    {
        $user = $this->userRepository->findByCalendarToken($token);
        if ($user === null || $user->getPerson() === null) {
            throw $this->createNotFoundException();
        }

        $absence = $this->absenceRepository->find($id);
        if ($absence === null || $absence->getPerson()->getId() !== $user->getPerson()->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('delete-absence-' . $id, $request->request->getString('_token'))) {
            return $this->redirectToRoute('calendar_token_view', ['token' => $token]);
        }

        $this->calendarService->deleteAbsence($absence);

        return $this->redirectToRoute('calendar_token_view', ['token' => $token]);
    }

    #[Route('/{token}/external-task/new', name: 'calendar_token_external_task_new', methods: ['POST'])]
    public function newExternalTask(string $token, Request $request): Response
    {
        $user = $this->userRepository->findByCalendarToken($token);
        if ($user === null || $user->getPerson() === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('calendar-token-ext-' . $token, $request->request->getString('_token'))) {
            return $this->redirectToRoute('calendar_token_view', ['token' => $token]);
        }

        try {
            $date = new \DateTimeImmutable($request->request->getString('date'));
        } catch (\Exception) {
            return $this->redirectToRoute('calendar_token_view', ['token' => $token]);
        }

        $description = trim($request->request->getString('description'));
        $person      = $user->getPerson();
        $assembly    = $person->getAssembly();

        $day = $this->dayRepository->findByAssemblyAndDate($assembly->getId(), $date);
        if ($day === null) {
            $day = new Day();
            $day->setAssembly($assembly);
            $day->setDate($date);
            $this->entityManager->persist($day);
        }

        $existing = $this->externalTaskRepository->findByPersonAndDay($person->getId(), $day->getId() ?? 0);
        if ($existing === null) {
            $et = new ExternalTask();
            $et->setPerson($person);
            $et->setDay($day);
            $et->setDescription($description ?: null);
            $this->entityManager->persist($et);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('calendar_token_view', [
            'token' => $token,
            'year'  => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
        ]);
    }

    #[Route('/{token}/external-task/{id}/delete', name: 'calendar_token_external_task_delete', methods: ['POST'])]
    public function deleteExternalTask(string $token, int $id, Request $request): Response
    {
        $user = $this->userRepository->findByCalendarToken($token);
        if ($user === null || $user->getPerson() === null) {
            throw $this->createNotFoundException();
        }

        $et = $this->externalTaskRepository->find($id);
        if ($et === null || $et->getPerson()->getId() !== $user->getPerson()->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('delete-ext-task-' . $id, $request->request->getString('_token'))) {
            return $this->redirectToRoute('calendar_token_view', ['token' => $token]);
        }

        $this->entityManager->remove($et);
        $this->entityManager->flush();

        return $this->redirectToRoute('calendar_token_view', ['token' => $token]);
    }
}
