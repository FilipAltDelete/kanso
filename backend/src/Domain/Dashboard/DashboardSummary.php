<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Dashboard;

use Kanso\Core\Internal\Domain\Order\OrderStatus;

/** The operator's day at a glance, as counted at one moment. */
final readonly class DashboardSummary
{
    /** Confirmed but not yet shipped: the warehouse's queue. */
    public const array AWAITING_FULFILLMENT = [OrderStatus::Confirmed, OrderStatus::Allocated, OrderStatus::Picking, OrderStatus::Packed];

    /**
     * @param array<string, int> $ordersByStatus every status, zeros included
     * @param list<StockOut>     $stockOuts      the first few, by SKU
     */
    public function __construct(
        public int $ordersToday,
        public int $awaitingFulfillment,
        public int $shippedToday,
        public array $ordersByStatus,
        public int $stockOutCount,
        public array $stockOuts,
    ) {
    }
}
