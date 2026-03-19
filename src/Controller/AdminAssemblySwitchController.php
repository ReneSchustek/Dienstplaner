<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AssemblyContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/assembly-switch')]
#[IsGranted('ROLE_ADMIN')]
/**
 * Ermöglicht Administratoren, in eine andere Versammlung zu wechseln.
 *
 * Der Wechsel wird in der Session gespeichert und beeinflusst alle
 * nachfolgenden Datenbankabfragen über AssemblyContext.
 */
class AdminAssemblySwitchController extends AbstractController
{
    public function __construct(private readonly AssemblyContext $assemblyContext) {}

    #[Route('/{id}', name: 'admin_assembly_switch', methods: ['POST'])]
    public function switch(Request $request, int $id): RedirectResponse
    {
        if ($this->isCsrfTokenValid('assembly-switch', $request->getPayload()->getString('_token'))) {
            $this->assemblyContext->setActiveAssembly($id);
        }

        $referer = $request->headers->get('referer');
        return $this->redirect($referer ?: $this->generateUrl('dashboard'));
    }

    #[Route('/reset', name: 'admin_assembly_reset', methods: ['POST'])]
    public function reset(Request $request): RedirectResponse
    {
        if ($this->isCsrfTokenValid('assembly-switch', $request->getPayload()->getString('_token'))) {
            $this->assemblyContext->resetToOwnAssembly();
        }

        $referer = $request->headers->get('referer');
        return $this->redirect($referer ?: $this->generateUrl('dashboard'));
    }
}
