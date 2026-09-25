<?php

declare(strict_types=1);

namespace Acme\AcmeBundle\Controller;

use Acme\AcmeBundle\Message\SyncOrdersToErp;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * `POST /api/ext/acme/erp-sync`: queue a sync. Behind the core's firewall like
 * every `/api` route, and checked against the core's roles.
 */
final readonly class ErpSyncController
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    #[Route('/erp-sync', name: 'acme_erp_sync', methods: ['POST'])]
    #[IsGranted('ROLE_OPERATOR')]
    public function __invoke(#[CurrentUser] UserInterface $user): JsonResponse
    {
        $this->bus->dispatch(new SyncOrdersToErp($user->getUserIdentifier()));

        return new JsonResponse(['status' => 'queued'], Response::HTTP_ACCEPTED);
    }
}
