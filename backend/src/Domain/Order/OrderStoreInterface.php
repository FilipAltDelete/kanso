<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Kanso\Core\Internal\Domain\Common\Page;

interface OrderStoreInterface
{
    public function findById(string $id): ?Order;

    /** @return Page<Order> */
    public function search(OrderQuery $query): Page;

    /**
     * The next order number. Taken outside the order's transaction, so a
     * failed create leaves a gap in the numbering rather than holding a lock.
     */
    public function nextNumber(): string;

    /**
     * Stages a new order; it is written, with its lines and first event, when
     * the surrounding TransactionInterface::run() commits.
     */
    public function add(Order $order): void;
}
