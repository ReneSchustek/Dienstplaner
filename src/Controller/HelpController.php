<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Zeigt die Hilfeseite der Anwendung.
 */
#[IsGranted('ROLE_USER')]
class HelpController extends AbstractController
{
    #[Route('/help', name: 'help_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($request->query->getBoolean('popup')) {
            return $this->render('help/popup.html.twig');
        }

        return $this->render('help/index.html.twig');
    }
}
