<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/**
 * One edit of an order before fulfillment, already checked by the
 * application layer: new quantities for some lines, lines to remove, lines to
 * add, and the customer's name, email and addresses. A null field (or an
 * unset flag) leaves that part of the order as it is.
 */
final readonly class OrderEdit
{
    /**
     * @param list<array{line: OrderLine, quantity: int}> $quantities      new ordered quantities
     * @param list<OrderLine>                             $removed
     * @param list<NewOrderLine>                          $added
     * @param array<string, string|null>|null             $shippingAddress
     * @param array<string, string|null>|null             $billingAddress  with $changeBilling: null clears it
     */
    public function __construct(
        public array $quantities = [],
        public array $removed = [],
        public array $added = [],
        public ?string $customerName = null,
        public bool $changeEmail = false,
        public ?string $customerEmail = null,
        public ?array $shippingAddress = null,
        public bool $changeBilling = false,
        public ?array $billingAddress = null,
    ) {
    }
}
