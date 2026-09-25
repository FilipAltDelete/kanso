<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Kanso\Core\Internal\Domain\Catalog\Product;

/**
 * A line as given when an order is placed: the product it is for, the name
 * to show on the order (the product's unless the channel says otherwise), and
 * the unit price in minor units of the order's currency.
 */
final readonly class NewOrderLine
{
    public function __construct(
        public Product $product,
        public string $name,
        public int $quantity,
        public int $unitPrice,
    ) {
    }
}
