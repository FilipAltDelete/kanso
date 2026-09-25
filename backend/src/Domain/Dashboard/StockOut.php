<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Dashboard;

/** A product at a location with nothing available: everything on hand is reserved, or nothing is on hand. */
final readonly class StockOut
{
    public function __construct(
        public string $productId,
        public string $sku,
        public string $productName,
        public string $locationId,
        public string $locationCode,
        public string $locationName,
        public int $onHand,
        public int $reserved,
    ) {
    }
}
