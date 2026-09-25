<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Kanso\Core\Internal\Domain\Common\Page;

interface OrderStoreInterface
{
    public function findById(string $id): ?Order;

    /**
     * The orders there are among these ids, with their tags loaded; unknown ids are left out.
     *
     * @param list<string> $ids
     *
     * @return list<Order>
     */
    public function findByIds(array $ids): array;

    /** @return list<array{name: string, orders: int}> every tag in use and how many orders have it, by name */
    public function tagCounts(): array;

    /** @return Page<Order> with their tags loaded */
    public function search(OrderQuery $query): Page;

    /**
     * The orders a channel already has under these external references. The
     * column compares without regard to case or accents, like the SKU.
     *
     * @param list<string> $references
     *
     * @return list<Order>
     */
    public function findByExternalReferences(Channel $channel, array $references): array;

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
