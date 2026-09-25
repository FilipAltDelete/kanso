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
 * An order's stock follows its status: reserved on confirm, released on
 * cancel, taken off on hand on ship — in the same transaction as the status,
 * and never more than is there.
 */
final class OrderStockTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;
    private string $locationId;
    private string $locationCode;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->signInAs(Role::OPERATOR);

        $this->locationCode = 'WH-'.bin2hex(random_bytes(3));
        $this->locationId = (string) $this->api('POST', '/api/locations', ['code' => $this->locationCode, 'name' => 'Main'])['id'];
    }

    public function testConfirmingReservesAndTheOrderShowsIt(): void
    {
        $tee = $this->product(stock: 10);
        $order = $this->order([[$tee, 3]]);

        $confirmed = $this->transition($order, 'confirm');

        self::assertSame(200, $this->responseStatus());
        self::assertSame('confirmed', $confirmed['status']);
        self::assertSame(3, $confirmed['lines'][0]['reservedQuantity']);
        self::assertSame($this->locationCode, $confirmed['location']['code']);
        self::assertSame([10, 3, 7], $this->stock($tee));

        $movement = $this->api('GET', '/api/inventory-movements?product='.$tee['id'])['member'][0];
        self::assertSame(['reservation', null, $order['number'], $order['id']], [$movement['type'], $movement['reason'], $movement['orderNumber'], $movement['orderId']]);
        self::assertSame([0, 3, 0], [$movement['onHandChange'], $movement['reservedAfter'], $movement['reservedBefore']]);
    }

    public function testStockIsNeverOversold(): void
    {
        $tee = $this->product(stock: 5);
        $first = $this->order([[$tee, 3]]);
        $second = $this->order([[$tee, 3]]);
        $this->transition($first, 'confirm');

        $problem = $this->transition($second, 'confirm');

        self::assertSame(409, $this->responseStatus());
        self::assertSame([['path' => 'lines[0].quantity', 'message' => \sprintf('%s: 2 available at %s, 3 needed.', $tee['sku'], $this->locationCode), 'code' => 'insufficient_stock']], $problem['violations']);
        self::assertSame([5, 3, 2], $this->stock($tee), 'Only the first order holds stock.');
        self::assertSame('pending', $this->api('GET', '/api/orders/'.$second['id'])['status']);
    }

    public function testTwoLinesOfTheSameProductAreCountedTogether(): void
    {
        $tee = $this->product(stock: 5);

        $problem = $this->transition($this->order([[$tee, 3], [$tee, 3]]), 'confirm');

        self::assertSame(409, $this->responseStatus());
        self::assertStringContainsString('5 available', $problem['violations'][0]['message']);
        self::assertStringContainsString('6 needed', $problem['violations'][0]['message']);
    }

    public function testWhenOneLineIsShortNothingAtAllIsWritten(): void
    {
        $plenty = $this->product(stock: 10);
        $scarce = $this->product(stock: 1);
        $order = $this->order([[$plenty, 2], [$scarce, 2]]);
        $before = $this->snapshot($order);

        $problem = $this->transition($order, 'confirm');

        self::assertSame(409, $this->responseStatus());
        self::assertSame(['lines[1].quantity'], array_column($problem['violations'], 'path'), 'Only the short line is named.');
        self::assertSame($before, $this->snapshot($order), 'No status, no event, no reservation on the line that had enough, no movement.');
        self::assertSame([10, 0, 10], $this->stock($plenty));
    }

    public function testAProductWithNoStockAtTheLocationIsShort(): void
    {
        $problem = $this->transition($this->order([[$this->product(stock: 0), 1]]), 'confirm');

        self::assertSame(409, $this->responseStatus());
        self::assertSame('insufficient_stock', $problem['violations'][0]['code']);
    }

    public function testCancellingGivesTheStockBack(): void
    {
        $tee = $this->product(stock: 10);
        $order = $this->order([[$tee, 4]]);
        $confirmed = $this->transition($order, 'confirm');

        $cancelled = $this->transition($order, 'cancel', $confirmed['version']);

        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(0, $cancelled['lines'][0]['reservedQuantity']);
        self::assertSame([10, 0, 10], $this->stock($tee));
        self::assertSame(['release', 'reservation', 'adjustment'], array_column($this->api('GET', '/api/inventory-movements?product='.$tee['id'])['member'], 'type'));
    }

    public function testCancellingAnOrderThatHeldNothingMovesNoStock(): void
    {
        $tee = $this->product(stock: 10);

        $this->transition($this->order([[$tee, 4]]), 'cancel');

        self::assertSame([10, 0, 10], $this->stock($tee));
        self::assertSame(['adjustment'], array_column($this->api('GET', '/api/inventory-movements?product='.$tee['id'])['member'], 'type'));
    }

    public function testHoldingKeepsTheStockAndCancellingFromHoldReleasesIt(): void
    {
        $tee = $this->product(stock: 10);
        $order = $this->order([[$tee, 4]]);
        $version = $this->transition($order, 'confirm')['version'];

        $held = $this->transition($order, 'hold', $version);
        self::assertSame([10, 4, 6], $this->stock($tee), 'On hold from confirmed still holds the stock.');

        $released = $this->transition($order, 'release', $held['version']);
        self::assertSame([10, 4, 6], $this->stock($tee), 'Back to confirmed: nothing reserved twice.');

        $held = $this->transition($order, 'hold', $released['version']);
        $this->transition($order, 'cancel', $held['version']);
        self::assertSame([10, 0, 10], $this->stock($tee));
    }

    public function testShippingTakesTheReservedStockOffOnHand(): void
    {
        $tee = $this->product(stock: 10);
        $order = $this->order([[$tee, 3]]);
        $version = $this->transition($order, 'confirm')['version'];
        foreach (['allocate', 'start_picking', 'pack'] as $step) {
            $version = $this->transition($order, $step, $version)['version'];
            self::assertSame([10, 3, 7], $this->stock($tee), $step.' moves no stock');
        }

        $shipped = $this->api('POST', '/api/orders/'.$order['id'].'/shipments', ['version' => $version, 'lines' => [['lineId' => $order['lines'][0]['id'], 'quantity' => 3]]]);

        self::assertSame('shipped', $shipped['status']);
        self::assertSame([0, 3], [$shipped['lines'][0]['reservedQuantity'], $shipped['lines'][0]['shippedQuantity']]);
        self::assertSame([7, 0, 7], $this->stock($tee));
        self::assertSame(-3, $this->api('GET', '/api/inventory-movements?product='.$tee['id'])['member'][0]['onHandChange']);
    }

    public function testAnOrderLineMustBeForAKnownProduct(): void
    {
        $problem = $this->api('POST', '/api/orders', $this->orderBody([['sku' => 'NO-SUCH-'.bin2hex(random_bytes(3)), 'quantity' => 1, 'unitPrice' => 100]]));

        self::assertSame(422, $this->responseStatus());
        self::assertSame(['lines[0].sku' => 'unknown_sku'], array_column($problem['violations'], 'code', 'path'));
    }

    public function testALineTakesTheProductsNameUnlessGivenOne(): void
    {
        $tee = $this->product(stock: 1, name: 'Organic tee');

        $order = $this->api('POST', '/api/orders', $this->orderBody([
            ['sku' => $tee['sku'], 'quantity' => 1, 'unitPrice' => 100],
            ['sku' => $tee['sku'], 'name' => 'Tee (gift)', 'quantity' => 1, 'unitPrice' => 100],
        ]));

        self::assertSame(201, $this->responseStatus());
        self::assertSame(['Organic tee', 'Tee (gift)'], array_column($order['lines'], 'name'));
        self::assertSame([$tee['id'], $tee['id']], array_column($order['lines'], 'productId'));
    }

    public function testTheLocationIsTheOneNamedOrTheOnlyOne(): void
    {
        $tee = $this->product(stock: 1);

        $defaulted = $this->api('POST', '/api/orders', $this->orderBody([['sku' => $tee['sku'], 'quantity' => 1, 'unitPrice' => 100]], location: null));
        self::assertSame($this->locationCode, $defaulted['location']['code'], 'The only location is the default.');

        $this->api('POST', '/api/orders', $this->orderBody([['sku' => $tee['sku'], 'quantity' => 1, 'unitPrice' => 100]], location: 'NOWHERE'));
        self::assertSame(['location' => 'unknown_location'], array_column($this->json()['violations'], 'code', 'path'));

        $this->api('POST', '/api/locations', ['code' => 'ST-'.bin2hex(random_bytes(3)), 'name' => 'Store']);
        $this->api('POST', '/api/orders', $this->orderBody([['sku' => $tee['sku'], 'quantity' => 1, 'unitPrice' => 100]], location: null));
        self::assertSame(422, $this->responseStatus());
        self::assertSame(['location' => 'no_default_location'], array_column($this->json()['violations'], 'code', 'path'), 'Two locations and no default: the order has to say.');
    }

    public function testStockAtAnotherLocationDoesNotCount(): void
    {
        $tee = $this->product(stock: 10);
        $store = (string) $this->api('POST', '/api/locations', ['code' => 'ST-'.bin2hex(random_bytes(3)), 'name' => 'Store'])['code'];

        $this->transition($this->order([[$tee, 1]], $store), 'confirm');

        self::assertSame(409, $this->responseStatus());
    }

    /** @return array<string, mixed> the product */
    private function product(int $stock, string $name = 'Tee'): array
    {
        $product = $this->api('POST', '/api/products', ['sku' => 'TEE-'.bin2hex(random_bytes(3)), 'name' => $name]);
        if ($stock > 0) {
            $this->api('POST', '/api/stock-adjustments', ['productId' => $product['id'], 'locationId' => $this->locationId, 'delta' => $stock, 'reason' => 'received', 'expectedVersion' => 0]);
            self::assertSame(201, $this->responseStatus());
        }

        return $product;
    }

    /**
     * @param list<array{array<string, mixed>, int}> $lines product and quantity
     *
     * @return array<string, mixed> the order
     */
    private function order(array $lines, ?string $location = 'default'): array
    {
        $order = $this->api('POST', '/api/orders', $this->orderBody(
            array_map(static fn (array $line): array => ['sku' => $line[0]['sku'], 'quantity' => $line[1], 'unitPrice' => 19_950], $lines),
            'default' === $location ? $this->locationCode : $location,
        ));
        self::assertSame(201, $this->responseStatus(), json_encode($order, \JSON_THROW_ON_ERROR));

        return $order;
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function orderBody(array $lines, ?string $location = 'default'): array
    {
        return [
            'customer' => ['name' => 'Anna Andersson'],
            'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'],
            'lines' => $lines,
            ...(null === $location ? [] : ['location' => 'default' === $location ? $this->locationCode : $location]),
        ];
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<mixed>
     */
    private function transition(array $order, string $transition, ?int $version = null): array
    {
        return $this->api('POST', '/api/orders/'.$order['id'].'/transitions', ['transition' => $transition, 'version' => $version ?? $order['version']]);
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array{int, int, int} on hand, reserved, available at the location
     */
    private function stock(array $product): array
    {
        $levels = $this->api('GET', \sprintf('/api/inventory-levels?product=%s&location=%s', $product['id'], $this->locationId))['member'];
        if ([] === $levels) {
            return [0, 0, 0];
        }

        return [$levels[0]['onHand'], $levels[0]['reserved'], $levels[0]['available']];
    }

    /**
     * Everything a confirmation could write for this order, straight from MySQL.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function snapshot(array $order): array
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $id = Uuid::fromString((string) $order['id'])->toBinary();

        return [
            'order' => $connection->fetchAssociative('SELECT status, version FROM sales_order WHERE id = ?', [$id]),
            'events' => (int) $connection->fetchOne('SELECT COUNT(*) FROM order_event WHERE order_id = ?', [$id]),
            'reserved' => $connection->fetchFirstColumn('SELECT reserved_quantity FROM order_line WHERE order_id = ? ORDER BY position', [$id]),
            'movements' => (int) $connection->fetchOne('SELECT COUNT(*) FROM inventory_movement WHERE order_id = ?', [$id]),
            'levels' => $connection->fetchAllAssociative('SELECT l.on_hand, l.reserved, l.version FROM inventory_level l JOIN order_line ol ON ol.product_id = l.product_id WHERE ol.order_id = ? ORDER BY ol.position', [$id]),
        ];
    }
}
