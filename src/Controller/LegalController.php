<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Zeigt statische Rechtsseiten (Impressum, Datenschutz).
 *
 * Routen sind öffentlich ohne Login erreichbar.
 */
class LegalController extends AbstractController
{
    #[Route('/impressum', name: 'legal_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('legal/index.html.twig');
    }

    #[Route('/datenschutz', name: 'legal_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }
}
