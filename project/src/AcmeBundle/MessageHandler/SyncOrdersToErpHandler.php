<?php

declare(strict_types=1);

namespace Acme\AcmeBundle\MessageHandler;

use Acme\AcmeBundle\Message\SyncOrdersToErp;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A placeholder until the core publishes an order reader in kanso/contracts
 * (Phase 1). A real handler reads orders through that contract, calls the ERP,
 * and throws to let Messenger retry.
 */
#[AsMessageHandler]
final readonly class SyncOrdersToErpHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(SyncOrdersToErp $message): void
    {
        $this->logger->info('ERP sync requested', ['requestedBy' => $message->requestedBy]);
    }
}
