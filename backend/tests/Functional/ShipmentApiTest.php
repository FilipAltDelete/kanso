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
 * Shipments through the API and MySQL: each takes its units off on hand and
 * off the reservation, in one transaction with the order, and the order ships
 * when the last unit does.
 */
final class ShipmentApiTest extends WebTestCase
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

    public function testAPartialShipmentTakesItsUnitsOffOnHandAndOffTheReservation(): void
    {
        $order = $this->confirmed([[$this->tee, 5], [$this->socks, 2]]);
        self::assertSame([10, 5, 5], $this->stock($this->tee));

        $shipped = $this->ship($order, [[0, 2]], carrier: 'PostNord', tracking: '00370712345');

        self::assertSame(201, $this->responseStatus());
        self::assertSame('confirmed', $shipped['status'], 'Units are left.');
        self::assertSame([[3, 2], [2, 0]], array_map(static fn (array $line): array => [$line['reservedQuantity'], $line['shippedQuantity']], $shipped['lines']));
        self::assertSame([8, 3, 5], $this->stock($this->tee), 'On hand and reserved both down by 2; available unchanged.');
        self::assertSame([6, 2, 4], $this->stock($this->socks), 'A line not in the shipment is not touched.');
        self::assertTrue($shipped['canShip']);

        $shipment = $shipped['shipments'][0];
        self::assertSame(['PostNord', '00370712345', $this->locationCode], [$shipment['carrier'], $shipment['trackingNumber'], $shipment['location']['code']]);
        self::assertSame([[$this->tee['sku'], 2]], array_map(static fn (array $line): array => [$line['sku'], $line['quantity']], $shipment['lines']));

        $movement = $this->api('GET', '/api/inventory-movements?product='.$this->tee['id'])['member'][0];
        self::assertSame(['shipment', $order['number'], -2, 5, 3], [$movement['type'], $movement['orderNumber'], $movement['onHandChange'], $movement['reservedBefore'], $movement['reservedAfter']]);
        self::assertSame('shipment', $shipped['events'][array_key_last($shipped['events'])]['type']);
    }

    public function testTheOrderShipsWhenTheLastUnitDoes(): void
    {
        $order = $this->confirmed([[$this->tee, 5], [$this->socks, 2]]);
        $first = $this->ship($order, [[0, 3]]);
        $second = $this->ship($first, [[0, 1], [1, 2]]);
        self::assertSame('confirmed', $second['status']);

        $last = $this->ship($second, [[0, 1]], tracking: 'JD0123');

        self::assertSame('shipped', $last['status']);
        self::assertFalse($last['canShip']);
        self::assertCount(3, $last['shipments']);
        self::assertSame([[0, 5], [0, 2]], array_map(static fn (array $line): array => [$line['reservedQuantity'], $line['shippedQuantity']], $last['lines']));
        self::assertSame([5, 0, 5], $this->stock($this->tee));
        self::assertSame([4, 0, 4], $this->stock($this->socks));
        self::assertSame(['shipment', 'transition'], array_column(\array_slice($last['events'], -2), 'type'));
        self::assertNotContains('ship', $last['availableTransitions']);
    }

    public function testItShipsFromPackedToo(): void
    {
        $order = $this->confirmed([[$this->tee, 1]]);
        foreach (['allocate', 'start_picking', 'pack'] as $step) {
            $order = $this->api('POST', '/api/orders/'.$order['id'].'/transitions', ['transition' => $step, 'version' => $order['version']]);
        }

        self::assertSame('shipped', $this->ship($order, [[0, 1]])['status']);
    }

    public function testShippingMoreThanIsLeftChangesNothing(): void
    {
        $order = $this->confirmed([[$this->tee, 5], [$this->socks, 2]]);
        $order = $this->ship($order, [[0, 4]]);
        $before = $this->snapshot($order);

        $problem = $this->ship($order, [[1, 1], [0, 2]]);

        self::assertSame(422, $this->responseStatus());
        self::assertSame([['path' => 'lines[1].quantity', 'code' => 'exceeds_remaining']], array_map(static fn (array $v): array => ['path' => $v['path'], 'code' => $v['code']], $problem['violations']));
        self::assertSame($before, $this->snapshot($order), 'Not even the line that fitted.');
    }

    public function testAStaleVersionIsAConflictAndChangesNothing(): void
    {
        $order = $this->confirmed([[$this->tee, 5]]);
        $this->ship($order, [[0, 1]]);
        $before = $this->snapshot($order);

        $problem = $this->ship($order, [[0, 1]]);

        self::assertSame(409, $this->responseStatus());
        self::assertSame('stale_version', $problem['violations'][0]['code']);
        self::assertSame($before, $this->snapshot($order));
    }

    public function testAnOrderThatIsNotConfirmedCannotShip(): void
    {
        $order = $this->order([[$this->tee, 1]]);

        $problem = $this->ship($order, [[0, 1]]);

        self::assertSame(409, $this->responseStatus());
        self::assertSame('not_shippable', $problem['violations'][0]['code']);
        self::assertSame([10, 0, 10], $this->stock($this->tee));
    }

    public function testBadLinesAreNamed(): void
    {
        $order = $this->confirmed([[$this->tee, 2]]);
        $lineId = $order['lines'][0]['id'];

        $problem = $this->api('POST', '/api/orders/'.$order['id'].'/shipments', ['version' => $order['version'], 'lines' => [
            ['lineId' => $lineId, 'quantity' => 1],
            ['lineId' => $lineId, 'quantity' => 1],
            ['lineId' => Uuid::v7()->toRfc4122(), 'quantity' => 1],
            ['lineId' => $lineId, 'quantity' => 0],
        ]]);

        self::assertSame(422, $this->responseStatus());
        self::assertSame([
            'lines[1].lineId' => 'duplicate_line',
            'lines[2].lineId' => 'unknown_line',
            'lines[3].quantity' => 'out_of_range',
            'lines[3].lineId' => 'duplicate_line',
        ], array_column($problem['violations'], 'code', 'path'), 'Every problem, at its path.');
    }

    public function testShipIsNoLongerATransition(): void
    {
        $order = $this->confirmed([[$this->tee, 1]]);

        $problem = $this->api('POST', '/api/orders/'.$order['id'].'/transitions', ['transition' => 'ship', 'version' => $order['version']]);

        self::assertSame(409, $this->responseStatus());
        self::assertSame('use_shipments', $problem['violations'][0]['code']);
        self::assertSame([10, 1, 9], $this->stock($this->tee));
    }

    public function testAPartlyShippedOrderCannotBeCancelled(): void
    {
        $order = $this->ship($this->confirmed([[$this->tee, 3]]), [[0, 1]]);

        self::assertNotContains('cancel', $order['availableTransitions']);
        $this->api('POST', '/api/orders/'.$order['id'].'/transitions', ['transition' => 'cancel', 'version' => $order['version']]);
        self::assertSame(409, $this->responseStatus());
        self::assertSame([9, 2, 7], $this->stock($this->tee), 'The two still reserved stay reserved.');
    }

    public function testVoidingAShipmentPutsItsUnitsBackOnHandAndReserved(): void
    {
        $order = $this->ship($this->confirmed([[$this->tee, 5]]), [[0, 2]], carrier: 'DHL', tracking: 'X');
        $mistake = $order['shipments'][0]['id'];
        self::assertSame([8, 3, 5], $this->stock($this->tee));

        $voided = $this->api('POST', \sprintf('/api/orders/%s/shipments/%s/void', $order['id'], $mistake), ['version' => $order['version'], 'reason' => 'Recorded twice']);

        self::assertSame(200, $this->responseStatus());
        self::assertSame([10, 5, 5], $this->stock($this->tee), 'Back on hand and reserved; available never moved.');
        self::assertSame([5, 0], [$voided['lines'][0]['reservedQuantity'], $voided['lines'][0]['shippedQuantity']]);
        $shipment = $voided['shipments'][0];
        self::assertNotNull($shipment['voidedAt']);
        self::assertSame(['Recorded twice', false], [$shipment['voidReason'], $shipment['voidable']]);
        self::assertContains('cancel', $voided['availableTransitions'], 'Nothing has shipped any more.');
        $movement = $this->api('GET', '/api/inventory-movements?product='.$this->tee['id'])['member'][0];
        self::assertSame(['shipment_voided', 2, 3, 5], [$movement['type'], $movement['onHandChange'], $movement['reservedBefore'], $movement['reservedAfter']]);
    }

    public function testVoidingTheLastShipmentReopensTheOrder(): void
    {
        $order = $this->confirmed([[$this->tee, 2]]);
        $order = $this->api('POST', '/api/orders/'.$order['id'].'/transitions', ['transition' => 'allocate', 'version' => $order['version']]);
        $shipped = $this->ship($order, [[0, 2]]);
        self::assertSame('shipped', $shipped['status']);

        $reopened = $this->api('POST', \sprintf('/api/orders/%s/shipments/%s/void', $shipped['id'], $shipped['shipments'][0]['id']), ['version' => $shipped['version']]);

        self::assertSame('allocated', $reopened['status']);
        self::assertTrue($reopened['canShip']);
        self::assertSame([10, 2, 8], $this->stock($this->tee));
        self::assertSame(['shipment_voided', 'transition'], array_column(\array_slice($reopened['events'], -2), 'type'));
        self::assertSame('reopen', $reopened['events'][array_key_last($reopened['events'])]['transition']);
    }

    public function testAVoidedShipmentDoesNotCountAsShippedOnItsDay(): void
    {
        $order = $this->ship($this->confirmed([[$this->tee, 2]]), [[0, 1]]);
        $today = new \DateTimeImmutable('today')->format('Y-m-d');
        $shippedToday = fn (): array => array_column($this->api('GET', '/api/orders?shippedFrom='.$today.'&q='.$order['number'])['member'], 'number');
        self::assertSame([$order['number']], $shippedToday());

        $this->api('POST', \sprintf('/api/orders/%s/shipments/%s/void', $order['id'], $order['shipments'][0]['id']), ['version' => $order['version']]);

        self::assertSame([], $shippedToday(), 'It never left.');
    }

    public function testADeliveredOrdersShipmentCannotBeVoided(): void
    {
        $shipped = $this->ship($this->confirmed([[$this->tee, 1]]), [[0, 1]]);
        $delivered = $this->api('POST', '/api/orders/'.$shipped['id'].'/transitions', ['transition' => 'deliver', 'version' => $shipped['version']]);
        $before = $this->snapshot($delivered);

        $problem = $this->api('POST', \sprintf('/api/orders/%s/shipments/%s/void', $delivered['id'], $delivered['shipments'][0]['id']), ['version' => $delivered['version']]);

        self::assertSame(409, $this->responseStatus());
        self::assertSame('not_voidable', $problem['violations'][0]['code']);
        self::assertSame($before, $this->snapshot($delivered));
    }

    public function testAVoidWithAStaleVersionChangesNothing(): void
    {
        $order = $this->ship($this->confirmed([[$this->tee, 5]]), [[0, 2]]);
        $before = $this->snapshot($order);

        $this->api('POST', \sprintf('/api/orders/%s/shipments/%s/void', $order['id'], $order['shipments'][0]['id']), ['version' => $order['version'] - 1]);

        self::assertSame(409, $this->responseStatus());
        self::assertSame($before, $this->snapshot($order));
    }

    public function testATrackingNumberCanBeCorrectedWithoutMovingStock(): void
    {
        $order = $this->ship($this->confirmed([[$this->tee, 5]]), [[0, 2]], carrier: 'PostNord', tracking: '0037O');

        $corrected = $this->api('POST', \sprintf('/api/orders/%s/shipments/%s/tracking', $order['id'], $order['shipments'][0]['id']), ['version' => $order['version'], 'trackingNumber' => '00370']);

        self::assertSame(200, $this->responseStatus());
        self::assertSame(['PostNord', '00370'], [$corrected['shipments'][0]['carrier'], $corrected['shipments'][0]['trackingNumber']], 'The carrier was not sent, so it was kept.');
        self::assertSame([8, 3, 5], $this->stock($this->tee));
        $event = $corrected['events'][array_key_last($corrected['events'])];
        self::assertSame(['shipment_corrected', '0037O', '00370'], [$event['type'], $event['before']['trackingNumber'], $event['after']['trackingNumber']]);

        $cleared = $this->api('POST', \sprintf('/api/orders/%s/shipments/%s/tracking', $order['id'], $order['shipments'][0]['id']), ['version' => $corrected['version'], 'carrier' => null]);
        self::assertNull($cleared['shipments'][0]['carrier'] ?? null);
    }

    public function testWhenAShipmentLeftIsChecked(): void
    {
        $order = $this->confirmed([[$this->tee, 2]]);

        $problem = $this->api('POST', '/api/orders/'.$order['id'].'/shipments', ['version' => $order['version'], 'lines' => [['lineId' => $order['lines'][0]['id'], 'quantity' => 1]], 'shippedAt' => '2099-01-01T00:00:00Z']);
        self::assertSame(422, $this->responseStatus());
        self::assertSame(['shippedAt' => 'in_future'], array_column($problem['violations'], 'code', 'path'));

        $problem = $this->api('POST', '/api/orders/'.$order['id'].'/shipments', ['version' => $order['version'], 'lines' => [['lineId' => $order['lines'][0]['id'], 'quantity' => 1]], 'shippedAt' => '2001-01-01T00:00:00Z']);
        self::assertSame(['shippedAt' => 'before_placed'], array_column($problem['violations'], 'code', 'path'));

        // The minute the order was placed, as a person types it (no seconds):
        // allowed, though the order was placed some seconds into that minute.
        $placed = new \DateTimeImmutable((string) $order['placedAt']);
        $sameMinute = $placed->setTime((int) $placed->format('H'), (int) $placed->format('i'));
        $shipped = $this->api('POST', '/api/orders/'.$order['id'].'/shipments', ['version' => $order['version'], 'lines' => [['lineId' => $order['lines'][0]['id'], 'quantity' => 1]], 'shippedAt' => $sameMinute->format(\DATE_ATOM)]);
        self::assertSame(201, $this->responseStatus());
        self::assertNotEmpty($shipped['shipments'][0]['shippedAt']);
    }

    public function testAViewerCannotShip(): void
    {
        $order = $this->confirmed([[$this->tee, 1]]);
        $this->signInAs(Role::VIEWER);

        $this->ship($order, [[0, 1]]);

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
     * @param array<string, mixed>  $order
     * @param list<array{int, int}> $lines line index and quantity
     *
     * @return array<mixed>
     */
    private function ship(array $order, array $lines, ?string $carrier = null, ?string $tracking = null): array
    {
        return $this->api('POST', '/api/orders/'.$order['id'].'/shipments', [
            'version' => $order['version'],
            'lines' => array_map(static fn (array $line): array => ['lineId' => $order['lines'][$line[0]]['id'], 'quantity' => $line[1]], $lines),
            ...(null === $carrier ? [] : ['carrier' => $carrier]),
            ...(null === $tracking ? [] : ['trackingNumber' => $tracking]),
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
     * Everything a shipment could write for this order, straight from MySQL.
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
            'order' => $db->fetchAssociative('SELECT status, version FROM sales_order WHERE id = ?', [$id]),
            'lines' => $db->fetchAllAssociative('SELECT reserved_quantity, shipped_quantity FROM order_line WHERE order_id = ? ORDER BY position', [$id]),
            'shipments' => (int) $db->fetchOne('SELECT COUNT(*) FROM shipment WHERE order_id = ?', [$id]),
            'events' => (int) $db->fetchOne('SELECT COUNT(*) FROM order_event WHERE order_id = ?', [$id]),
            'movements' => (int) $db->fetchOne('SELECT COUNT(*) FROM inventory_movement WHERE order_id = ?', [$id]),
            'levels' => $db->fetchAllAssociative('SELECT l.on_hand, l.reserved, l.version FROM inventory_level l JOIN order_line ol ON ol.product_id = l.product_id WHERE ol.order_id = ? ORDER BY ol.position', [$id]),
        ];
    }
}
