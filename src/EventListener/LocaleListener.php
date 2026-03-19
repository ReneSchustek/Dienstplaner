<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
/**
 * Setzt die Sprache der Anwendung aus der Session.
 *
 * Wird bei jedem Request ausgeführt und liest den gespeicherten
 * Locale-Wert aus der Session, um die korrekte Übersetzung zu laden.
 */
class LocaleListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request->hasPreviousSession()) {
            return;
        }

        $locale = $request->getSession()->get('_locale');
        if ($locale) {
            $request->setLocale($locale);
        }
    }
}
