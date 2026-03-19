<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Leitet eingeloggte Benutzer auf die Passwortänderungsseite um,
 * wenn ihr Konto eine Passwortänderung erfordert (forcePasswordChange = true).
 *
 * Ausgenommen sind: die Änderungsseite selbst, Login, Logout und öffentliche Seiten.
 */
class ForcePasswordChangeSubscriber implements EventSubscriberInterface
{
    /** Routen, die trotz forcePasswordChange aufrufbar sein müssen. */
    private const ALLOWED_ROUTES = [
        'profile_index',
        'profile_change_password',
        'security_logout',
        'security_login',
        'password_reset_request',
        'legal_index',
        'legal_privacy',
        '_wdt',
        '_profiler',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RouterInterface $router,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User || !$user->isForcePasswordChange()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $url = $this->router->generate('profile_index');
        $event->setResponse(new RedirectResponse($url));
    }
}
