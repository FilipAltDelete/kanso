<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Doctrine\DBAL\Connection;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Editing orders and partial cancels through the API and MySQL (ADR-0011):
 * the reservation follows each change in the same transaction, and a refused
 * change writes nothing.
 */
final class OrderEditApiTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;
    private string $locationId;
    private string $locationCode;
    /** @var array<string, mixed> */
    private array $tee;
    /** @var array<string, mixed> */
    private array $socks;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->signInAs(Role::OPERATOR);
        $this->locationCode = 'WH-'.bin2hex(random_bytes(3));
        $this->locationId = (string) $this->api('POST', '/api/locations', ['code' => $this->locationCode, 'name' => 'Main'])['id'];
        $this->tee = $this->product(10);
        $this->socks = $this->product(6);
    }

    public function testEditingAConfirmedOrderMovesTheReservationUpAndDown(): void
    {
        $order = $this->confirmed([[$this->tee, 5], [$this->socks, 2]]);
        $cap = $this->product(4);
        self::assertSame([10, 5, 5], $this->stock($this->tee));

        $edited = $this->edit($order, ['lines' => [
            ['lineId' => $order['lines'][0]['id'], 'quantity' => 7],
            ['lineId' => $order['lines'][1]['id'], 'quantity' => 0],
            ['sku' => $cap['sku'], 'quantity' => 3, 'unitPrice' => 250],
        ]]);

        self::assertSame(200, $this->responseStatus());
        self::assertSame($order['version'] + 1, $edited['version']);
        self::assertSame([[$this->tee['sku'], 7, 7], [$cap['sku'], 3, 3]], array_map(static fn (array $line): array => [$line['sku'], $line['quantity'], $line['reservedQuantity']], $edited['lines']));
        self::assertSame(7 * 100 + 3 * 250, $edited['total']);
        self::assertSame([10, 7, 3], $this->stock($this->tee), 'Two more reserved.');
        self::assertSame([6, 0, 6], $this->stock($this->socks), 'The removed line gave its two back.');
        self::assertSame([4, 3, 1], $this->stock($cap), 'The new line is reserved.');

        $event = $edited['events'][array_key_last($edited['events'])];
        self::assertSame('edited', $event['type']);
        self::assertSame([[1, 5], [2, 2]], array_map(static fn (array $line): array => [$line['position'], $line['quantity']], $event['before']['lines']));
        self::assertSame([[1, 7], [3, 3]], array_map(static fn (array $line): array => [$line['position'], $line['quantity']], $event['after']['lines']));
        self::assertSame([5 * 100 + 2 * 100, 7 * 100 + 3 * 250], [$event['before']['total'], $event['after']['total']]);

        $movements = array_column($this->api('GET', '/api/inventory-movements?product='.$this->tee['id'])['member'], 'type');
        self::assertSame(['reservation', 'reservation'], \array_slice($movements, 0, 2), 'Confirm, then the edit: each with a movement.');
        self::assertSame('release', $this->api('GET', '/api/inventory-movements?product='.$this->socks['id'])['member'][0]['type']);
    }

    public function testShortStockIsAConflictNamingTheLinesAndChangesNothing(): void
    {
        $order = $this->confirmed([[$this->tee, 5], [$this->socks, 2]]);
        $before = $this->snapshot($order);

        $problem = $this->edit($order, ['lines' => [
            ['lineId' => $order['lines'][0]['id'], 'quantity' => 11],
            ['lineId' => $order['lines'][1]['id'], 'quantity' => 3],
            ['sku' => $this->socks['sku'], 'quantity' => 4, 'unitPrice' => 100],
        ]]);

        self::assertSame(409, $this->responseStatus());
        self::assertSame([
            'lines[0].quantity' => 'insufficient_stock',
            'lines[1].quantity' => 'insufficient_stock',
            'lines[2].quantity' => 'insufficient_stock',
        ], array_column($problem['violations'], 'code', 'path'), 'Socks need 5 more across two lines, with 4 available.');
        self::assertSame($before, $this->snapshot($order));
    }

    public function testAProductMovedBetweenLinesNeedsOnlyTheDifference(): void
    {
        $order = $this->confirmed([[$this->tee, 8]]);
        self::assertSame([10, 8, 2], $this->stock($this->tee));

        $edited = $this->edit($order, ['lines' => [
            ['lineId' => $order['lines'][0]['id'], 'quantity' => 0],
            ['sku' => $this->tee['sku'], 'quantity' => 9, 'unitPrice' => 90],
        ]]);

        self::assertSame(200, $this->responseStatus(), 'Eight given back and nine taken: one more than before, and two are available.');
        self::assertSame([[2, 9, 9]], array_map(static fn (array $line): array => [$line['position'], $line['quantity'], $line['reservedQuantity']], $edited['lines']));
        self::assertSame([10, 9, 1], $this->stock($this->tee));
    }

    public function testEditingAPendingOrderMovesNoStockAndChangesTheCustomer(): void
    {
        $order = $this->order([[$this->tee, 2]]);

        $edited = $this->edit($order, [
            'lines' => [['lineId' => $order['lines'][0]['id'], 'quantity' => 4]],
            'customer' => ['name' => 'Anna Berg', 'email' => 'anna@example.com'],
            'shippingAddress' => ['line1' => 'Nygatan 2', 'postalCode' => '222 33', 'city' => 'Lund', 'countryCode' => 'SE'],
            'billingAddress' => ['line1' => 'Box 1', 'postalCode' => '111 11', 'city' => 'Stockholm', 'countryCode' => 'SE'],
        ]);

        self::assertSame(200, $this->responseStatus());
        self::assertSame(['Anna Berg', 'anna@example.com'], [$edited['customer']['name'], $edited['customer']['email']]);
        self::assertSame(['Lund', 'Stockholm'], [$edited['shippingAddress']['city'], $edited['billingAddress']['city']]);
        self::assertSame([4, 0], [$edited['lines'][0]['quantity'], $edited['lines'][0]['reservedQuantity']]);
        self::assertSame([10, 0, 10], $this->stock($this->tee));
        $event = $edited['events'][array_key_last($edited['events'])];
        self::assertSame(['customerName' => 'Anna', 'customerEmail' => null], ['customerName' => $event['before']['customerName'], 'customerEmail' => $event['before']['customerEmail'] ?? null]);

        // Left out is unchanged; null clears the billing address.
        $cleared = $this->edit($edited, ['billingAddress' => null]);
        self::assertSame(200, $this->responseStatus());
        self::assertArrayNotHasKey('billingAddress', $cleared, 'Null, so left out of the response.');
        self::assertSame(['Anna Berg', 'Lund', 4], [$cleared['customer']['name'], $cleared['shippingAddress']['city'], $cleared['lines'][0]['quantity']]);
    }

    public function testAStaleVersionIsAConflict(): void
    {
        $order = $this->confirmed([[$this->tee, 2]]);
        $this->edit($order, ['customer' => ['name' => 'Someone']]);
        self::assertSame(200, $this->responseStatus());
        $before = $this->snapshot($order);

        $problem = $this->edit($order, ['lines' => [['lineId' => $order['lines'][0]['id'], 'quantity' => 3]]]);

        self::assertSame(409, $this->responseStatus());
        self::assertSame('stale_version', $problem['violations'][0]['code']);
        self::assertSame($before, $this->snapshot($order));

        $this->cancel($order, [[0, 1]]);
        self::assertSame(409, $this->responseStatus());
        self::assertSame($before, $this->snapshot($order));
    }

    public function testAnOrderBeingPickedCannotBeEdited(): void
    {
        $order = $this->confirmed([[$this->tee, 2]]);
        foreach (['allocate', 'start_picking'] as $step) {
            $order = $this->api('POST', '/api/orders/'.$order['id'].'/transitions', ['transition' => $step, 'version' => $order['version']]);
        }
        self::assertFalse($order['canEdit']);
        self::assertTrue($order['canCancelItems']);
        $before = $this->snapshot($order);

        $problem = $this->edit($order, ['lines' => [['lineId' => $order['lines'][0]['id'], 'quantity' => 1]]]);

        self::assertSame(409, $this->responseStatus());
        self::assertSame('not_editable', $problem['violations'][0]['code']);
        self::assertSame($before, $this->snapshot($order));
    }

    public function testBadEditsAreNamed(): void
    {
        $order = $this->order([[$this->tee, 2]]);
        $lineId = $order['lines'][0]['id'];

        $problem = $this->edit($order, ['lines' => [
            ['lineId' => $lineId, 'quantity' => 1, 'unitPrice' => 1],
            ['lineId' => $lineId, 'quantity' => 1],
            ['lineId' => Uuid::v7()->toRfc4122(), 'quantity' => 1],
            ['sku' => 'NO-SUCH-SKU', 'quantity' => 1, 'unitPrice' => 100],
        ], 'customer' => ['name' => '']]);

        self::assertSame(422, $this->responseStatus());
        self::assertSame([
            'customer.name' => 'required',
            'lines[0].unitPrice' => 'not_editable',
            'lines[1].lineId' => 'duplicate_line',
            'lines[2].lineId' => 'unknown_line',
            'lines[3].sku' => 'unknown_sku',
        ], array_column($problem['violations'], 'code', 'path'));

        $this->edit($order, ['lines' => [['lineId' => $lineId, 'quantity' => 0]]]);
        self::assertSame(422, $this->responseStatus(), 'Removing the only line leaves nothing: cancel the order instead.');
        self::assertSame('nothing_left', $this->json()['violations'][0]['code']);
    }

    public function testAPartialCancelReleasesItsUnits(): void
    {
        $order = $this->confirmed([[$this->tee, 5], [$this->socks, 2]]);

        $cancelled = $this->cancel($order, [[0, 2]], reason: 'Damaged');

        self::assertSame(200, $this->responseStatus());
        self::assertSame('confirmed', $cancelled['status']);
        self::assertSame([[5, 3, 2], [2, 2, 0]], array_map(static fn (array $line): array => [$line['quantity'], $line['reservedQuantity'], $line['cancelledQuantity']], $cancelled['lines']));
        self::assertSame(3 * 100 + 2 * 100, $cancelled['total']);
        self::assertSame([10, 3, 7], $this->stock($this->tee));
        self::assertSame([6, 2, 4], $this->stock($this->socks));
        self::assertTrue($cancelled['canCancelItems']);

        $event = $cancelled['events'][array_key_last($cancelled['events'])];
        self::assertSame(['lines_cancelled', 'Damaged', 2], [$event['type'], $event['after']['reason'], $event['after']['lines'][0]['cancelled']]);
        $movement = $this->api('GET', '/api/inventory-movements?product='.$this->tee['id'])['member'][0];
        self::assertSame(['release', $order['number'], 5, 3], [$movement['type'], $movement['orderNumber'], $movement['reservedBefore'], $movement['reservedAfter']]);
    }

    public function testCancellingEveryUnitInPartsCancelsTheOrder(): void
    {
        $order = $this->confirmed([[$this->tee, 5], [$this->socks, 2]]);
        $order = $this->cancel($order, [[0, 5]]);

        $last = $this->cancel($order, [[1, 2]]);

        self::assertSame('cancelled', $last['status']);
        self::assertSame(0, $last['total']);
        self::assertSame(['lines_cancelled', 'transition'], array_column(\array_slice($last['events'], -2), 'type'));
        self::assertSame('cancel', $last['events'][array_key_last($last['events'])]['transition']);
        self::assertSame([10, 0, 10], $this->stock($this->tee));
        self::assertSame([6, 0, 6], $this->stock($this->socks));
        self::assertFalse($last['canCancelItems']);
    }

    public function testCancellingTheUnshippedRestShipsTheOrder(): void
    {
        $order = $this->confirmed([[$this->tee, 5]]);
        $order = $this->api('POST', '/api/orders/'.$order['id'].'/shipments', ['version' => $order['version'], 'lines' => [['lineId' => $order['lines'][0]['id'], 'quantity' => 3]]]);
        self::assertSame([7, 2, 5], $this->stock($this->tee));

        $problem = $this->cancel($order, [[0, 3]]);
        self::assertSame(422, $this->responseStatus(), 'Shipped units cannot be cancelled.');
        self::assertSame('exceeds_remaining', $problem['violations'][0]['code']);

        $shipped = $this->cancel($order, [[0, 2]]);

        self::assertSame('shipped', $shipped['status']);
        self::assertSame([[5, 0, 3, 2]], array_map(static fn (array $line): array => [$line['quantity'], $line['reservedQuantity'], $line['shippedQuantity'], $line['cancelledQuantity']], $shipped['lines']));
        self::assertSame(3 * 100, $shipped['total']);
        self::assertSame([7, 0, 7], $this->stock($this->tee));
        self::assertSame('ship', $shipped['events'][array_key_last($shipped['events'])]['transition']);
    }

    public function testNothingIsCancelledOnceTheOrderIsCancelled(): void
    {
        $order = $this->order([[$this->tee, 2]]);
        $order = $this->api('POST', '/api/orders/'.$order['id'].'/transitions', ['transition' => 'cancel', 'version' => $order['version']]);

        $problem = $this->cancel($order, [[0, 1]]);

        self::assertSame(409, $this->responseStatus());
        self::assertSame('not_cancellable', $problem['violations'][0]['code']);
    }

    public function testCancelledUnitsAreNotReservedOnConfirm(): void
    {
        $order = $this->cancel($this->order([[$this->tee, 4]]), [[0, 1]]);

        $confirmed = $this->api('POST', '/api/orders/'.$order['id'].'/transitions', ['transition' => 'confirm', 'version' => $order['version']]);

        self::assertSame(200, $this->responseStatus());
        self::assertSame([4, 3, 1], [$confirmed['lines'][0]['quantity'], $confirmed['lines'][0]['reservedQuantity'], $confirmed['lines'][0]['cancelledQuantity']]);
        self::assertSame([10, 3, 7], $this->stock($this->tee));
    }

    public function testAViewerCanNeitherEditNorCancel(): void
    {
        $order = $this->confirmed([[$this->tee, 2]]);
        $this->signInAs(Role::VIEWER);

        $this->edit($order, ['customer' => ['name' => 'Someone']]);
        self::assertSame(403, $this->responseStatus());
        $this->cancel($order, [[0, 1]]);
        self::assertSame(403, $this->responseStatus());
    }

    /** @return array<string, mixed> */
    private function product(int $stock): array
    {
        $product = $this->api('POST', '/api/products', ['sku' => 'SKU-'.bin2hex(random_bytes(3)), 'name' => 'Thing']);
        $this->api('POST', '/api/stock-adjustments', ['productId' => $product['id'], 'locationId' => $this->locationId, 'delta' => $stock, 'reason' => 'received', 'expectedVersion' => 0]);

        return $product;
    }

    /**
     * @param list<array{array<string, mixed>, int}> $lines
     *
     * @return array<string, mixed>
     */
    private function order(array $lines): array
    {
        $order = $this->api('POST', '/api/orders', [
            'location' => $this->locationCode,
            'customer' => ['name' => 'Anna'],
            'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'],
            'lines' => array_map(static fn (array $line): array => ['sku' => $line[0]['sku'], 'quantity' => $line[1], 'unitPrice' => 100], $lines),
        ]);
        self::assertSame(201, $this->responseStatus());

        return $order;
    }

    /**
     * @param list<array{array<string, mixed>, int}> $lines
     *
     * @return array<string, mixed>
     */
    private function confirmed(array $lines): array
    {
        $order = $this->order($lines);
        $confirmed = $this->api('POST', '/api/orders/'.$order['id'].'/transitions', ['transition' => 'confirm', 'version' => $order['version']]);
        self::assertSame(200, $this->responseStatus());

        return $confirmed;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $changes
     *
     * @return array<mixed>
     */
    private function edit(array $order, array $changes): array
    {
        return $this->api('POST', '/api/orders/'.$order['id'].'/edits', ['version' => $order['version'], ...$changes]);
    }

    /**
     * @param array<string, mixed>  $order
     * @param list<array{int, int}> $lines line index and quantity
     *
     * @return array<mixed>
     */
    private function cancel(array $order, array $lines, ?string $reason = null): array
    {
        return $this->api('POST', '/api/orders/'.$order['id'].'/cancellations', [
            'version' => $order['version'],
            'lines' => array_map(static fn (array $line): array => ['lineId' => $order['lines'][$line[0]]['id'], 'quantity' => $line[1]], $lines),
            ...(null === $reason ? [] : ['reason' => $reason]),
        ]);
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array{int, int, int} on hand, reserved, available
     */
    private function stock(array $product): array
    {
        $level = $this->api('GET', \sprintf('/api/inventory-levels?product=%s&location=%s', $product['id'], $this->locationId))['member'][0];

        return [$level['onHand'], $level['reserved'], $level['available']];
    }

    /**
     * Everything an edit or a cancel could write for this order, straight from MySQL.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function snapshot(array $order): array
    {
        $db = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $id = Uuid::fromString((string) $order['id'])->toBinary();

        return [
            'order' => $db->fetchAssociative('SELECT status, version, total_amount, customer_name FROM sales_order WHERE id = ?', [$id]),
            'lines' => $db->fetchAllAssociative('SELECT position, quantity, reserved_quantity, cancelled_quantity, line_total FROM order_line WHERE order_id = ? ORDER BY position', [$id]),
            'events' => (int) $db->fetchOne('SELECT COUNT(*) FROM order_event WHERE order_id = ?', [$id]),
            'movements' => (int) $db->fetchOne('SELECT COUNT(*) FROM inventory_movement WHERE order_id = ?', [$id]),
            'levels' => $db->fetchAllAssociative('SELECT l.on_hand, l.reserved, l.version FROM inventory_level l JOIN order_line ol ON ol.product_id = l.product_id WHERE ol.order_id = ? ORDER BY ol.position', [$id]),
        ];
    }
}
