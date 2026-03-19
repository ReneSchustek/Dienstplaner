<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Setzt die Anzeigesprache der Anwendung per Session.
 *
 * Erlaubte Sprachen: de, en, fr.
 */
class LanguageController extends AbstractController
{
    private const ALLOWED_LOCALES = ['de', 'en', 'fr'];

    #[Route('/language/{locale}', name: 'language_switch', methods: ['GET'])]
    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (in_array($locale, self::ALLOWED_LOCALES, true)) {
            $request->getSession()->set('_locale', $locale);
        }

        $referer = $request->headers->get('referer');

        return $this->redirect($referer ?: $this->generateUrl('dashboard'));
    }
}
