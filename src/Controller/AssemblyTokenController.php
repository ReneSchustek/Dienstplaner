<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AbsenceRepository;
use App\Repository\AssemblyRepository;
use App\Repository\AssignmentRepository;
use App\Repository\ExternalTaskRepository;
use App\Service\AssemblyContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Verwaltung und öffentlicher Abruf von Versammlungs-Kalender-Links.
 *
 * ROLE_ASSEMBLY_ADMIN kann Tokens generieren und widerrufen.
 * Die öffentlichen Endpunkte sind ohne Anmeldung zugänglich.
 */
class AssemblyTokenController extends AbstractController
{
    public function __construct(
        private readonly AssemblyRepository $assemblyRepository,
        private readonly AbsenceRepository $absenceRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly ExternalTaskRepository $externalTaskRepository,
        private readonly AssemblyContext $assemblyContext,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    // === Admin-Bereich: Token-Verwaltung ===

    #[Route('/einstellungen/kalender-links', name: 'assembly_token_manage', methods: ['GET'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function manage(): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            $this->addFlash('warning', 'planning.no_assembly');
            return $this->redirectToRoute('dashboard');
        }

        return $this->render('assembly/tokens.html.twig', [
            'assembly' => $assembly,
        ]);
    }

    #[Route('/einstellungen/kalender-links/absence/generate', name: 'assembly_token_absence_generate', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function generateAbsenceToken(Request $request): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->redirectToRoute('dashboard');
        }

        if (!$this->isCsrfTokenValid('assembly-absence-token-' . $assembly->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('assembly_token_manage');
        }

        $assembly->setPublicAbsenceToken(bin2hex(random_bytes(32)));
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.assembly_token.generated');
        return $this->redirectToRoute('assembly_token_manage');
    }

    #[Route('/einstellungen/kalender-links/absence/revoke', name: 'assembly_token_absence_revoke', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function revokeAbsenceToken(Request $request): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->redirectToRoute('dashboard');
        }

        if (!$this->isCsrfTokenValid('assembly-absence-token-revoke-' . $assembly->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('assembly_token_manage');
        }

        $assembly->setPublicAbsenceToken(null);
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.assembly_token.revoked');
        return $this->redirectToRoute('assembly_token_manage');
    }

    #[Route('/einstellungen/kalender-links/calendar/generate', name: 'assembly_token_calendar_generate', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function generateCalendarToken(Request $request): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->redirectToRoute('dashboard');
        }

        if (!$this->isCsrfTokenValid('assembly-calendar-token-' . $assembly->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('assembly_token_manage');
        }

        $assembly->setPublicCalendarToken(bin2hex(random_bytes(32)));
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.assembly_token.generated');
        return $this->redirectToRoute('assembly_token_manage');
    }

    #[Route('/einstellungen/kalender-links/calendar/revoke', name: 'assembly_token_calendar_revoke', methods: ['POST'])]
    #[IsGranted('ROLE_ASSEMBLY_ADMIN')]
    public function revokeCalendarToken(Request $request): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $assembly = $this->assemblyContext->getActiveAssembly($user);

        if ($assembly === null) {
            return $this->redirectToRoute('dashboard');
        }

        if (!$this->isCsrfTokenValid('assembly-calendar-token-revoke-' . $assembly->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'flash.csrf.invalid');
            return $this->redirectToRoute('assembly_token_manage');
        }

        $assembly->setPublicCalendarToken(null);
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.assembly_token.revoked');
        return $this->redirectToRoute('assembly_token_manage');
    }

    // === Öffentliche Endpunkte ===

    #[Route('/versammlung/{token}/abwesenheiten', name: 'assembly_public_absences', methods: ['GET'])]
    public function publicAbsences(string $token, Request $request): Response
    {
        $assembly = $this->assemblyRepository->findByPublicAbsenceToken($token);
        if ($assembly === null) {
            throw $this->createNotFoundException();
        }

        $year  = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        $from     = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to       = new \DateTimeImmutable($from->format('Y-m-t'));
        $absences = $this->absenceRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to);

        return $this->render('assembly/public_absences.html.twig', [
            'assembly' => $assembly,
            'absences' => $absences,
            'year'     => $year,
            'month'    => $month,
            'token'    => $token,
        ]);
    }

    #[Route('/versammlung/{token}/kalender', name: 'assembly_public_calendar', methods: ['GET'])]
    public function publicCalendar(string $token, Request $request): Response
    {
        $assembly = $this->assemblyRepository->findByPublicCalendarToken($token);
        if ($assembly === null) {
            throw $this->createNotFoundException();
        }

        $year  = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        $from        = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to          = new \DateTimeImmutable($from->format('Y-m-t'));
        $absences    = $this->absenceRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to);
        $assignments = $this->assignmentRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to);
        $extTasks    = $this->externalTaskRepository->findByAssemblyAndPeriod($assembly->getId(), $from, $to);

        return $this->render('assembly/public_calendar.html.twig', [
            'assembly'    => $assembly,
            'absences'    => $absences,
            'assignments' => $assignments,
            'extTasks'    => $extTasks,
            'year'        => $year,
            'month'       => $month,
            'token'       => $token,
        ]);
    }
}
