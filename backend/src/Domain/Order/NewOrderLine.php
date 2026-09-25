<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/** A line as given when an order is placed; the unit price is in minor units of the order's currency. */
final readonly class NewOrderLine
{
    public function __construct(
        public string $skuCode,
        public string $name,
        public int $quantity,
        public int $unitPrice,
    ) {
    }
}
