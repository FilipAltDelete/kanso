<?php

declare(strict_types=1);

namespace Acme\AcmeBundle\Message;

/**
 * Push orders to Acme's ERP. The shape of most customer integrations: a small
 * message on the `ext` transport, handled by a worker with retries.
 */
final readonly class SyncOrdersToErp
{
    public function __construct(
        /** Who or what asked for the sync, for the log line. */
        public string $requestedBy,
    ) {
    }
}
