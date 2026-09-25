<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Kanso\Core\Internal\Domain\Dashboard\DashboardQueryInterface;
use Kanso\Core\Internal\Domain\Dashboard\DashboardSummary;
use Kanso\Core\Internal\Domain\Dashboard\StockOut;
use Kanso\Core\Internal\Domain\Order\OrderStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Plain SQL, no entities: the dashboard counts, and each count is answered
 * from an index (Version20260927130000, Version20260928120000):
 *
 *   orders today         idx_sales_order_placed (placed_at)
 *   orders by status     idx_sales_order_status_placed (status, placed_at)
 *   shipped today        idx_shipment_shipped (shipped_at, order_id)
 *   stock-outs           idx_inventory_level_available ((on_hand - reserved))
 */
final class DashboardQuery implements DashboardQueryInterface
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function summary(\DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd, int $stockOutLimit): DashboardSummary
    {
        $from = $dayStart->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
        $to = $dayEnd->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);

        $ordersToday = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM sales_order WHERE placed_at >= ? AND placed_at < ?',
            [$from, $to],
        );

        $byStatus = array_fill_keys(OrderStatus::values(), 0);
        foreach ($this->connection->fetchAllKeyValue('SELECT status, COUNT(*) FROM sales_order GROUP BY status') as $status => $count) {
            if (\array_key_exists((string) $status, $byStatus)) {
                $byStatus[(string) $status] = (int) $count;
            }
        }

        $awaiting = 0;
        foreach (DashboardSummary::AWAITING_FULFILLMENT as $status) {
            $awaiting += $byStatus[$status->value];
        }

        // Orders with a parcel out today, counted once each however many they
        // had: a partly shipped order was shipped today too. The day of the
        // shipment, not the day the order was placed. A voided shipment never left.
        $shippedToday = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT order_id) FROM shipment WHERE shipped_at >= ? AND shipped_at < ? AND voided_at IS NULL',
            [$from, $to],
        );

        // on_hand >= reserved is a CHECK constraint, so "nothing available" is exactly zero.
        $stockOutCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_level WHERE (on_hand - reserved) = 0');

        $stockOuts = [];
        if ($stockOutCount > 0 && $stockOutLimit > 0) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT p.id AS product_id, p.sku, p.name AS product_name, l.id AS location_id, l.code AS location_code, l.name AS location_name, il.on_hand, il.reserved
                   FROM inventory_level il
                   JOIN product p ON p.id = il.product_id
                   JOIN location l ON l.id = il.location_id
                  WHERE (il.on_hand - il.reserved) = 0
                  ORDER BY p.sku, l.code
                  LIMIT '.$stockOutLimit,
            );
            foreach ($rows as $row) {
                $stockOuts[] = new StockOut(
                    (string) Uuid::fromBinary((string) $row['product_id']),
                    (string) $row['sku'],
                    (string) $row['product_name'],
                    (string) Uuid::fromBinary((string) $row['location_id']),
                    (string) $row['location_code'],
                    (string) $row['location_name'],
                    (int) $row['on_hand'],
                    (int) $row['reserved'],
                );
            }
        }

        return new DashboardSummary($ordersToday, $awaiting, $shippedToday, $byStatus, $stockOutCount, $stockOuts);
    }
}
