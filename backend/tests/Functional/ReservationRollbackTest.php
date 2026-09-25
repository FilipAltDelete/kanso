<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Doctrine\DBAL\Connection;
use Kanso\Core\Internal\Application\Catalog\CatalogService;
use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Inventory\InventoryService;
use Kanso\Core\Internal\Application\Order\OrderService;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The failure that comes last: the stock is reserved, and then the order
 * itself cannot be saved because someone changed it meanwhile. The
 * reservation must go with it.
 */
final class ReservationRollbackTest extends KernelTestCase
{
    public function testAReservationIsUndoneWhenTheOrderCannotBeSaved(): void
    {
        $catalog = self::getContainer()->get(CatalogService::class);
        $inventory = self::getContainer()->get(InventoryService::class);
        $orders = self::getContainer()->get(OrderService::class);
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(CatalogService::class, $catalog);
        self::assertInstanceOf(InventoryService::class, $inventory);
        self::assertInstanceOf(OrderService::class, $orders);
        self::assertInstanceOf(Connection::class, $db);
        $actor = new Actor('test', 'Test');

        $location = $catalog->createLocation('RB-'.bin2hex(random_bytes(3)), 'Main', new Address());
        $product = $catalog->createProduct('RB-'.bin2hex(random_bytes(3)), 'Tee', null, null);
        $inventory->adjust((string) $product->id(), (string) $location->id(), 10, null, 'received', null, 0, $actor);
        $order = $orders->create([
            'location' => $location->code(),
            'customer' => ['name' => 'Anna'],
            'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'],
            'lines' => [['sku' => $product->sku(), 'quantity' => 3, 'unitPrice' => 100]],
        ], $actor);
        $id = $order->id()->toBinary();

        // Someone else saves the order after we loaded it: our copy still
        // says version 1, so the version check up front passes and the stock
        // is reserved — and then the UPDATE of the order finds version 2.
        $db->executeStatement('UPDATE sales_order SET version = version + 1 WHERE id = ?', [$id]);

        try {
            $orders->transition((string) $order->id(), 'confirm', 1, $actor);
            self::fail('Saving over a change we never saw must be refused.');
        } catch (Conflict $conflict) {
            self::assertSame('stale_version', $conflict->violations()[0]['code']);
        }

        self::assertSame(['status' => 'pending', 'version' => 2], array_map(static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value, (array) $db->fetchAssociative('SELECT status, version FROM sales_order WHERE id = ?', [$id])));
        self::assertSame(['on_hand' => 10, 'reserved' => 0], array_map(intval(...), (array) $db->fetchAssociative('SELECT on_hand, reserved FROM inventory_level WHERE product_id = ?', [$product->id()->toBinary()])));
        self::assertSame(0, (int) $db->fetchOne('SELECT reserved_quantity FROM order_line WHERE order_id = ?', [$id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM inventory_movement WHERE order_id = ?', [$id]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM order_event WHERE order_id = ?', [$id]), 'Only the created event.');
    }
}
