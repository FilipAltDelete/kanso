<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Application\Catalog\CatalogService;
use Kanso\Core\Internal\Application\Inventory\InventoryService;
use Kanso\Core\Internal\Application\User\UserService;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Security\AccessTokenIssuerInterface;
use Kanso\Core\Internal\Domain\User\Role;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The dashboard counts the whole installation, so every test reads it before
 * and after and checks the difference its own orders and stock made.
 */
final class DashboardApiTest extends WebTestCase
{
    private const string ZONE = 'Europe/Stockholm';

    private KernelBrowser $client;
    private string $operator;
    private string $location;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->operator = $this->token(Role::OPERATOR);
        $this->location = 'D'.strtoupper(bin2hex(random_bytes(3)));
        $this->catalog()->createLocation($this->location, 'Dashboard test', new Address());
    }

    public function testOrdersAreCountedThroughTheirDay(): void
    {
        $sku = $this->stockedProduct(50);
        $before = $this->dashboard();

        $first = $this->createOrder($sku, 1);
        $second = $this->createOrder($sku, 1);
        // Placed two days ago: in the status counts, not in today's.
        $this->createOrder($sku, 1, new \DateTimeImmutable('-2 days'));

        $first = $this->move($first, 'confirm');
        foreach (['confirm', 'allocate', 'start_picking', 'pack', 'ship'] as $transition) {
            $second = $this->move($second, $transition);
        }
        self::assertSame('confirmed', $first['status']);
        self::assertSame('shipped', $second['status']);

        $after = $this->dashboard();
        self::assertSame(2, $after['ordersToday'] - $before['ordersToday']);
        self::assertSame(1, $after['awaitingFulfillment'] - $before['awaitingFulfillment'], 'the confirmed one; the shipped one has left the queue');
        self::assertSame(1, $after['shippedToday'] - $before['shippedToday']);
        self::assertSame(1, $after['ordersByStatus']['pending'] - $before['ordersByStatus']['pending']);
        self::assertSame(1, $after['ordersByStatus']['confirmed'] - $before['ordersByStatus']['confirmed']);
        self::assertSame(1, $after['ordersByStatus']['shipped'] - $before['ordersByStatus']['shipped']);
        self::assertSame(0, $after['ordersByStatus']['on_hold'] - $before['ordersByStatus']['on_hold'], 'every status is listed, zeros too');
        self::assertCount(9, $after['ordersByStatus']);
    }

    public function testAProductWithNothingAvailableIsAStockOut(): void
    {
        $before = $this->dashboard();

        // Everything reserved: on hand, but not available.
        $reserved = $this->stockedProduct(2, '0000-RES');
        $this->move($this->createOrder($reserved, 2), 'confirm');
        // Counted down to nothing.
        $empty = $this->stockedProduct(3, '0000-EMP');
        $this->inventory()->adjust($this->productId($empty), $this->locationId(), null, 0, 'count', null, 1, new Actor('test', 'Test'));
        // Stock left: not a stock-out.
        $this->stockedProduct(5, '0000-OK');

        $after = $this->dashboard();
        self::assertSame(2, $after['stockOuts']['count'] - $before['stockOuts']['count']);

        $ours = array_values(array_filter($after['stockOuts']['items'], fn (array $item): bool => $item['locationCode'] === $this->location));
        self::assertSame([$empty, $reserved], array_column($ours, 'sku'), 'by SKU');
        self::assertSame(['onHand' => 2, 'reserved' => 2], array_intersect_key($ours[1], ['onHand' => 0, 'reserved' => 0]));
        self::assertSame($this->productId($reserved), $ours[1]['productId']);
        self::assertSame('Dashboard test', $ours[1]['locationName']);
    }

    public function testTodayIsTheCallersDay(): void
    {
        $dashboard = $this->dashboard();

        $zone = new \DateTimeZone(self::ZONE);
        self::assertSame(self::ZONE, $dashboard['timeZone']);
        self::assertSame(new \DateTimeImmutable('now', $zone)->format('Y-m-d'), $dashboard['date']);
        self::assertSame(
            new \DateTimeImmutable($dashboard['date'].' 00:00', $zone)->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            $dashboard['dayStart'],
        );

        $this->request('GET', '/api/dashboard', $this->operator);
        self::assertResponseIsSuccessful();
        self::assertSame('UTC', $this->json()['timeZone'], 'UTC when none is given');
    }

    public function testAnUnknownTimeZoneIsAProblem(): void
    {
        $this->request('GET', '/api/dashboard?timeZone=Mars/Olympus', $this->operator);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_time_zone', $this->json()['violations'][0]['code']);
    }

    public function testAnyoneSignedInMayLookButNobodyElse(): void
    {
        $this->request('GET', '/api/dashboard', $this->token(Role::VIEWER));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/dashboard');
        self::assertResponseStatusCodeSame(401);
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        $this->request('GET', '/api/dashboard?timeZone='.rawurlencode(self::ZONE), $this->operator);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /** A new product with `$quantity` on hand at this test's location; returns its SKU. */
    private function stockedProduct(int $quantity, string $prefix = 'DASH'): string
    {
        $sku = $prefix.'-'.strtoupper(bin2hex(random_bytes(3)));
        $product = $this->catalog()->createProduct($sku, 'Product '.$sku, null, null);
        $this->inventory()->adjust((string) $product->id(), $this->locationId(), $quantity, null, 'received', null, 0, new Actor('test', 'Test'));

        return $sku;
    }

    /** @return array<string, mixed> */
    private function createOrder(string $sku, int $quantity, ?\DateTimeImmutable $placedAt = null): array
    {
        $this->request('POST', '/api/orders', $this->operator, [
            'location' => $this->location,
            'customer' => ['name' => 'Dashboard Test'],
            'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'],
            'lines' => [['sku' => $sku, 'name' => $sku, 'quantity' => $quantity, 'unitPrice' => 10_000]],
            ...(null === $placedAt ? [] : ['placedAt' => $placedAt->format(\DATE_ATOM)]),
        ]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function move(array $order, string $transition): array
    {
        $this->request('POST', '/api/orders/'.$order['id'].'/transitions', $this->operator, ['transition' => $transition, 'version' => $order['version']]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    private function productId(string $sku): string
    {
        $this->request('GET', '/api/products?q='.rawurlencode($sku), $this->operator);
        $matches = array_values(array_filter($this->json()['member'], static fn (array $product): bool => $product['sku'] === $sku));
        self::assertCount(1, $matches);

        return $matches[0]['id'];
    }

    private function locationId(): string
    {
        $this->request('GET', '/api/locations?q='.rawurlencode($this->location), $this->operator);
        $matches = array_values(array_filter($this->json()['member'], fn (array $location): bool => $location['code'] === $this->location));
        self::assertCount(1, $matches);

        return $matches[0]['id'];
    }

    private function catalog(): CatalogService
    {
        $catalog = static::getContainer()->get(CatalogService::class);
        self::assertInstanceOf(CatalogService::class, $catalog);

        return $catalog;
    }

    private function inventory(): InventoryService
    {
        $inventory = static::getContainer()->get(InventoryService::class);
        self::assertInstanceOf(InventoryService::class, $inventory);

        return $inventory;
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, string $token, ?array $body = null): void
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/json',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function token(string $role): string
    {
        $users = static::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $users);
        $issuer = static::getContainer()->get(AccessTokenIssuerInterface::class);
        self::assertInstanceOf(AccessTokenIssuerInterface::class, $issuer);

        return $issuer->issue($users->create('u-'.bin2hex(random_bytes(4)).'@example.com', 'secret', [$role], 'Dashboard Tester'));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
