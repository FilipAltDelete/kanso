<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Order;

use Kanso\Core\Internal\Domain\Order\Order;

/** What a bulk transition did: the orders that moved, and why the others did not. */
final readonly class BulkTransitionResult
{
    /**
     * @param list<Order>                                                             $moved
     * @param list<array{id: string, number: ?string, code: string, message: string}> $failed
     */
    public function __construct(
        public string $transition,
        public array $moved,
        public array $failed,
    ) {
    }
}
