<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Application\Catalog\CatalogService;
use Kanso\Core\Internal\Application\Inventory\InventoryService;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** POST /api/orders/bulk-transitions: each order moves on its own; one that cannot is reported, not fatal. */
final class BulkTransitionApiTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->signInAs(Role::OPERATOR);

        // One location, so it is the default; 5 T-shirts in stock.
        $catalog = static::getContainer()->get(CatalogService::class);
        $inventory = static::getContainer()->get(InventoryService::class);
        self::assertInstanceOf(CatalogService::class, $catalog);
        self::assertInstanceOf(InventoryService::class, $inventory);
        $location = $catalog->createLocation('WH1', 'Main', new Address());
        $product = $catalog->createProduct('TEE-1', 'T-shirt', null, null);
        $inventory->adjust((string) $product->id(), (string) $location->id(), 5, null, 'received', null, 0, new Actor('test', 'Test'));
    }

    public function testOrdersThatCanMoveDoAndTheOthersAreReportedWithTheirReason(): void
    {
        $small = $this->order(2);
        $alsoSmall = $this->order(3);
        $tooBig = $this->order(10);

        $result = $this->bulk('confirm', [$small, $alsoSmall, $tooBig]);

        self::assertSame(200, $this->responseStatus());
        self::assertSame([$small['number'], $alsoSmall['number']], array_column($result['moved'], 'number'));
        self::assertSame(['confirmed', 'confirmed'], array_column($result['moved'], 'status'));
        self::assertSame([[$tooBig['number'], 'insufficient_stock']], array_map(static fn (array $failure): array => [$failure['number'], $failure['code']], $result['failed']));
        self::assertSame('pending', $this->api('GET', '/api/orders/'.$tooBig['id'])['status']);
    }

    public function testAnOrderChangedSinceItsVersionIsReportedNotOverwritten(): void
    {
        $order = $this->order(1);
        $this->api('POST', '/api/orders/'.$order['id'].'/transitions', ['transition' => 'hold', 'version' => $order['version']]);

        $result = $this->bulk('cancel', [$order]);

        self::assertSame([], $result['moved']);
        self::assertSame('stale_version', $result['failed'][0]['code']);
        self::assertSame('on_hold', $this->api('GET', '/api/orders/'.$order['id'])['status']);
    }

    public function testWithoutAVersionTheCurrentOneIsUsedAndATransitionNotAllowedIsReported(): void
    {
        $pending = $this->order(1);
        $held = $this->order(1);
        $this->api('POST', '/api/orders/'.$held['id'].'/transitions', ['transition' => 'hold', 'version' => 1]);

        $result = $this->bulk('release', [['id' => $pending['id']], ['id' => $held['id']], ['id' => '01a0d7ae-0000-7000-8000-000000000000']]);

        self::assertSame([$held['number']], array_column($result['moved'], 'number'));
        self::assertSame(['transition_not_allowed', 'not_found'], array_map(static fn (array $failure): string => $failure['code'], $result['failed']));
    }

    public function testABadRequestChangesNothing(): void
    {
        $order = $this->order(1);

        $problem = $this->bulk('teleport', [$order]);

        self::assertSame(422, $this->responseStatus());
        self::assertSame('unknown_transition', $problem['violations'][0]['code']);
        self::assertSame('pending', $this->api('GET', '/api/orders/'.$order['id'])['status']);
    }

    public function testAViewerCannotMoveOrders(): void
    {
        $order = $this->order(1);
        $this->signInAs(Role::VIEWER);

        $this->bulk('confirm', [$order]);

        self::assertSame(403, $this->responseStatus());
    }

    /** @return array<mixed> */
    private function order(int $quantity): array
    {
        $order = $this->api('POST', '/api/orders', [
            'customer' => ['name' => 'Anna'],
            'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'],
            'lines' => [['sku' => 'TEE-1', 'quantity' => $quantity, 'unitPrice' => 100]],
        ]);
        self::assertSame(201, $this->responseStatus());

        return $order;
    }

    /**
     * @param list<array<mixed>> $orders
     *
     * @return array<mixed>
     */
    private function bulk(string $transition, array $orders): array
    {
        return $this->api('POST', '/api/orders/bulk-transitions', [
            'transition' => $transition,
            'orders' => array_map(static fn (array $order): array => array_intersect_key($order, ['id' => true, 'version' => true]), $orders),
        ]);
    }
}
