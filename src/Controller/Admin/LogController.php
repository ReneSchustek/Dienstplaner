<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Zeigt die Anwendungs-Logs im Admin-Bereich an. */
#[Route('/admin/logs')]
#[IsGranted('ROLE_ADMIN')]
class LogController extends AbstractController
{
    private const MAX_LINES = 500;

    #[Route('', name: 'admin_logs', methods: ['GET'])]
    public function index(): Response
    {
        $env    = $this->getParameter('kernel.environment');
        $logDir = $this->getParameter('kernel.logs_dir');

        $logFile = $this->findLatestLogFile($logDir, $env);

        $lines = [];
        if ($logFile !== null && is_readable($logFile)) {
            $all   = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_reverse(array_slice($all, -self::MAX_LINES));
        }

        return $this->render('admin/logs.html.twig', [
            'lines'   => $lines,
            'logFile' => $logFile ?? $logDir . '/' . $env . '.log (nicht vorhanden)',
        ]);
    }

    /** Sucht die neueste rotierende Log-Datei (z.B. prod-2026-03-19.log). */
    private function findLatestLogFile(string $logDir, string $env): ?string
    {
        $pattern = $logDir . '/' . $env . '-*.log';
        $files   = glob($pattern) ?: [];

        if (empty($files)) {
            // Fallback auf nicht-rotierende Variante
            $plain = $logDir . '/' . $env . '.log';
            return file_exists($plain) ? $plain : null;
        }

        usort($files, fn(string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }
}
