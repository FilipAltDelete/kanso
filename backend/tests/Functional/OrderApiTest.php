<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Doctrine\DBAL\Connection;
use Kanso\Core\Internal\Application\Catalog\CatalogService;
use Kanso\Core\Internal\Application\Inventory\InventoryService;
use Kanso\Core\Internal\Application\User\UserService;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Security\AccessTokenIssuerInterface;
use Kanso\Core\Internal\Domain\User\Role;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $operator;
    private string $viewer;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->operator = $this->token(Role::OPERATOR, 'Olle Operator');
        $this->viewer = $this->token(Role::VIEWER, 'Vera Viewer');

        // One location, so it is the default, and plenty of every SKU these
        // tests order, so confirming is never short.
        $catalog = static::getContainer()->get(CatalogService::class);
        $inventory = static::getContainer()->get(InventoryService::class);
        self::assertInstanceOf(CatalogService::class, $catalog);
        self::assertInstanceOf(InventoryService::class, $inventory);
        $location = $catalog->createLocation('WH1', 'Main', new Address());
        foreach (['TSHIRT-M', 'SOCKS', 'A', 'B', 'C', 'X'] as $sku) {
            $product = $catalog->createProduct($sku, $sku, null, null);
            $inventory->adjust((string) $product->id(), (string) $location->id(), 100, null, 'received', null, 0, new Actor('test', 'Test'));
        }
    }

    public function testCreateAnOrderManually(): void
    {
        $order = $this->create([
            'customer' => ['name' => 'Anna Andersson', 'email' => 'anna@example.com'],
            'lines' => [
                ['sku' => 'TSHIRT-M', 'name' => 'T-shirt, M', 'quantity' => 3, 'unitPrice' => 19_950],
                ['sku' => 'SOCKS', 'name' => 'Socks', 'quantity' => 2, 'unitPrice' => 4_900],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertMatchesRegularExpression('/^\d{5,}$/', $order['number']);
        self::assertSame('pending', $order['status']);
        self::assertSame(['code' => 'manual', 'name' => 'Manual'], $order['channel']);
        self::assertSame('SEK', $order['currency']);
        self::assertSame(69_650, $order['total']);
        self::assertSame(1, $order['version']);
        self::assertSame(['id' => null, 'name' => 'Anna Andersson', 'email' => 'anna@example.com'], $order['customer']);
        self::assertSame('SE', $order['shippingAddress']['countryCode']);
        self::assertSame([59_850, 9_800], array_column($order['lines'], 'lineTotal'));
        self::assertSame(['confirm', 'cancel', 'hold'], $order['availableTransitions']);

        self::assertCount(1, $order['events']);
        self::assertSame('created', $order['events'][0]['type']);
        self::assertSame('Olle Operator', $order['events'][0]['actor']['name']);
    }

    public function testOrderNumbersIncrease(): void
    {
        $first = (int) $this->create()['number'];
        $second = (int) $this->create()['number'];

        self::assertGreaterThan($first, $second);
    }

    public function testTheOrderCanNameItsCurrencyCustomerRecordAndPlacedTime(): void
    {
        $this->request('POST', '/api/customers', $this->operator, ['name' => 'Bo', 'email' => 'bo-'.bin2hex(random_bytes(3)).'@example.com']);
        self::assertResponseStatusCodeSame(201);
        $customerId = $this->json()['id'];

        $order = $this->create([
            'currency' => 'EUR',
            'placedAt' => '2026-09-20T10:15:00+02:00',
            'customer' => ['id' => $customerId, 'name' => 'Bo'],
            'billingAddress' => ['line1' => 'Box 1', 'postalCode' => '111 11', 'city' => 'Stockholm', 'countryCode' => 'SE'],
        ]);

        self::assertSame('EUR', $order['currency']);
        self::assertSame('2026-09-20T08:15:00+00:00', $order['placedAt']);
        self::assertSame($customerId, $order['customer']['id']);
        self::assertSame('Box 1', $order['billingAddress']['line1']);
    }

    public function testEveryProblemInACreateIsReportedAtItsPath(): void
    {
        $this->request('POST', '/api/orders', $this->operator, [
            'channel' => 'nope',
            'currency' => 'kronor',
            'customer' => ['name' => '', 'email' => 'not-an-email', 'id' => 'x'],
            'shippingAddress' => ['line1' => 'Storgatan 1', 'city' => 'Stockholm', 'countryCode' => 'Sweden'],
            'lines' => [
                ['sku' => 'A', 'name' => 'A', 'quantity' => 0, 'unitPrice' => 100],
                ['sku' => 'B', 'name' => 'B', 'quantity' => 1, 'unitPrice' => 12.5],
                ['sku' => 'C', 'name' => 'C', 'quantity' => 1, 'unitPrice' => '100'],
                ['name' => 'D', 'quantity' => 1, 'unitPrice' => -1],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $paths = array_column($this->json()['violations'], 'code', 'path');
        self::assertSame([
            'channel' => 'unknown_channel',
            'currency' => 'currency',
            'customer.id' => 'uuid',
            'customer.name' => 'required',
            'customer.email' => 'email',
            'shippingAddress.countryCode' => 'country',
            'shippingAddress.postalCode' => 'required',
            'lines[0].quantity' => 'out_of_range',
            'lines[1].unitPrice' => 'type',
            'lines[2].unitPrice' => 'type',
            'lines[3].sku' => 'required',
            'lines[3].unitPrice' => 'out_of_range',
        ], $paths);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM sales_order WHERE customer_email = ?', ['not-an-email']));
    }

    public function testAnOrderNeedsLines(): void
    {
        $this->request('POST', '/api/orders', $this->operator, $this->body(['lines' => []]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('lines', $this->json()['violations'][0]['path']);
    }

    public function testAViewerCanReadButNotCreateOrMove(): void
    {
        $order = $this->create();

        $this->request('GET', '/api/orders/'.$order['id'], $this->viewer);
        self::assertResponseIsSuccessful();

        $this->request('POST', '/api/orders', $this->viewer, $this->body());
        self::assertResponseStatusCodeSame(403);

        $this->request('POST', '/api/orders/'.$order['id'].'/transitions', $this->viewer, ['transition' => 'confirm', 'version' => 1]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheDetailHasLinesAddressesAndTheTimeline(): void
    {
        $created = $this->create();
        $this->transition($created['id'], 'confirm', 1);

        $this->request('GET', '/api/orders/'.$created['id'], $this->viewer);
        self::assertResponseIsSuccessful();
        $order = $this->json();

        self::assertSame('confirmed', $order['status']);
        self::assertSame(2, $order['version']);
        self::assertSame(['created', 'transition'], array_column($order['events'], 'type'));
        self::assertSame(['status' => 'pending', 'heldFrom' => null], $order['events'][1]['before']);
        self::assertSame(['status' => 'confirmed', 'heldFrom' => null], $order['events'][1]['after']);
        self::assertSame('Storgatan 1', $order['shippingAddress']['line1']);
        self::assertCount(1, $order['lines']);
    }

    public function testAnUnknownOrderIsNotFound(): void
    {
        $this->request('GET', '/api/orders/0192f1a4-7b6e-7c3d-9a1b-2c3d4e5f6a7b', $this->viewer);
        self::assertResponseStatusCodeSame(404);

        $this->request('POST', '/api/orders/0192f1a4-7b6e-7c3d-9a1b-2c3d4e5f6a7b/transitions', $this->operator, ['transition' => 'confirm', 'version' => 1]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testATransitionWritesItsEventInTheSameTransaction(): void
    {
        $order = $this->create();

        $moved = $this->transition($order['id'], 'confirm', 1);
        self::assertResponseIsSuccessful();
        self::assertSame('confirmed', $moved['status']);
        self::assertSame(['allocate', 'cancel', 'hold'], $moved['availableTransitions']);

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT e.type, e.transition, e.actor_name, o.status, o.version FROM order_event e JOIN sales_order o ON o.id = e.order_id WHERE o.number = ? ORDER BY e.id',
            [$order['number']],
        );
        self::assertSame([
            ['type' => 'created', 'transition' => null, 'actor_name' => 'Olle Operator', 'status' => 'confirmed', 'version' => 2],
            ['type' => 'transition', 'transition' => 'confirm', 'actor_name' => 'Olle Operator', 'status' => 'confirmed', 'version' => 2],
        ], $rows);
    }

    public function testAForbiddenTransitionIsAConflictAndChangesNothing(): void
    {
        $order = $this->create();

        $this->transition($order['id'], 'deliver', 1);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('An order that is pending cannot deliver.', $this->json()['detail']);
        self::assertSame('transition_not_allowed', $this->json()['violations'][0]['code']);
        self::assertSame(1, $this->eventCount($order['number']));
        self::assertSame('pending', $this->connection()->fetchOne('SELECT status FROM sales_order WHERE number = ?', [$order['number']]));
    }

    public function testAStaleVersionIsAConflict(): void
    {
        $order = $this->create();
        $this->transition($order['id'], 'hold', 1);

        // A second operator still looking at version 1.
        $this->transition($order['id'], 'cancel', 1);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_version', $this->json()['violations'][0]['code']);
        self::assertSame(2, $this->eventCount($order['number']));
    }

    public function testAnUnknownTransitionOrMissingVersionIsInvalid(): void
    {
        $order = $this->create();

        $this->request('POST', '/api/orders/'.$order['id'].'/transitions', $this->operator, ['transition' => 'teleport']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['transition' => 'unknown_transition', 'version' => 'required'], array_column($this->json()['violations'], 'code', 'path'));
    }

    public function testHoldAndReleaseThroughTheApi(): void
    {
        $order = $this->create();
        $this->transition($order['id'], 'confirm', 1);
        $held = $this->transition($order['id'], 'hold', 2);
        self::assertSame(['on_hold', 'confirmed'], [$held['status'], $held['heldFrom']]);

        $released = $this->transition($order['id'], 'release', 3);
        // Null fields are left out of the response (API Platform's default).
        self::assertSame('confirmed', $released['status']);
        self::assertArrayNotHasKey('heldFrom', $released);
    }

    public function testTheListFiltersSearchesSortsAndPages(): void
    {
        $tag = 'Zed'.bin2hex(random_bytes(3));
        $a = $this->create(['customer' => ['name' => $tag.' Adams'], 'placedAt' => '2026-09-01T12:00:00Z']);
        $b = $this->create(['customer' => ['name' => $tag.' Berg'], 'placedAt' => '2026-09-10T12:00:00Z', 'lines' => [['sku' => 'X', 'name' => 'X', 'quantity' => 1, 'unitPrice' => 100]]]);
        $c = $this->create(['customer' => ['name' => $tag.' Cole', 'email' => strtolower($tag).'@example.com'], 'placedAt' => '2026-09-20T12:00:00Z']);
        $this->transition($b['id'], 'confirm', 1);

        $list = $this->list(['q' => $tag]);
        self::assertSame(3, $list['totalItems']);
        self::assertSame([$c['number'], $b['number'], $a['number']], array_column($list['member'], 'number'), 'newest first by default');
        self::assertArrayNotHasKey('lines', $list['member'][0]);
        self::assertSame(1, $list['member'][0]['lineCount']);

        self::assertSame([$b['number']], array_column($this->list(['q' => $tag, 'status' => 'confirmed'])['member'], 'number'));
        self::assertSame([$c['number'], $a['number']], array_column($this->list(['q' => $tag, 'status' => 'pending,cancelled'])['member'], 'number'));
        self::assertSame(3, $this->list(['q' => $tag, 'channel' => 'manual'])['totalItems']);
        self::assertSame(0, $this->list(['q' => $tag, 'channel' => 'shopify'])['totalItems']);
        self::assertSame([$b['number']], array_column($this->list(['q' => $tag, 'placedFrom' => '2026-09-05', 'placedBefore' => '2026-09-15T00:00:00+02:00'])['member'], 'number'));
        self::assertSame([$c['number']], array_column($this->list(['q' => strtolower($tag).'@example'])['member'], 'number'), 'search matches the email');
        self::assertSame([$b['number'], $c['number'], $a['number']], array_column($this->list(['q' => $tag, 'sort' => 'total,-placedAt'])['member'], 'number'), 'equal totals newest first');
        self::assertSame([$a['number'], $b['number'], $c['number']], array_column($this->list(['q' => $tag, 'sort' => 'customerName'])['member'], 'number'));

        $page = $this->list(['q' => $tag, 'sort' => 'placedAt', 'itemsPerPage' => 2, 'page' => 2]);
        self::assertSame(3, $page['totalItems']);
        self::assertSame([$c['number']], array_column($page['member'], 'number'));
    }

    public function testASearchTermIsLiteral(): void
    {
        $this->create(['customer' => ['name' => 'Percent Person']]);

        self::assertSame(0, $this->list(['q' => '%'])['totalItems']);
    }

    public function testBadListParametersAreReported(): void
    {
        $this->request('GET', '/api/orders?status=lost&sort=colour&placedFrom=tomorrow', $this->viewer);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['status' => 'unknown_status', 'sort' => 'unknown_sort', 'placedFrom' => 'date_time'], array_column($this->json()['violations'], 'code', 'path'));
    }

    public function testChannelsAreListed(): void
    {
        $this->request('GET', '/api/channels', $this->viewer);

        self::assertResponseIsSuccessful();
        self::assertContains(['code' => 'manual', 'name' => 'Manual', 'type' => 'manual', 'currency' => 'SEK'], array_map(
            static fn (array $channel): array => array_intersect_key($channel, array_flip(['code', 'name', 'type', 'currency'])),
            $this->json()['member'],
        ));
    }

    public function testTheOpenApiDocumentDescribesTheOrderEndpoints(): void
    {
        $this->request('GET', '/api/docs.json', $this->viewer, accept: 'application/json');

        self::assertResponseIsSuccessful();
        $paths = $this->json()['paths'];
        self::assertArrayHasKey('/api/orders', $paths);
        self::assertArrayHasKey('/api/orders/{id}/transitions', $paths);
        self::assertContains('status', array_column($paths['/api/orders']['get']['parameters'], 'name'));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_replace([
            'customer' => ['name' => 'Anna Andersson'],
            'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'],
            'lines' => [['sku' => 'TSHIRT-M', 'name' => 'T-shirt, M', 'quantity' => 1, 'unitPrice' => 19_950]],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function create(array $overrides = []): array
    {
        $this->request('POST', '/api/orders', $this->operator, $this->body($overrides));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function transition(string $id, string $transition, int $version): array
    {
        $this->request('POST', '/api/orders/'.$id.'/transitions', $this->operator, ['transition' => $transition, 'version' => $version]);

        return $this->json();
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return array<string, mixed>
     */
    private function list(array $query): array
    {
        $this->request('GET', '/api/orders?'.http_build_query($query), $this->viewer);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, string $token, ?array $body = null, string $accept = 'application/ld+json'): void
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => $accept,
            'CONTENT_TYPE' => 'application/json',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function token(string $role, string $name): string
    {
        $users = static::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $users);
        $issuer = static::getContainer()->get(AccessTokenIssuerInterface::class);
        self::assertInstanceOf(AccessTokenIssuerInterface::class, $issuer);

        return $issuer->issue($users->create('u-'.bin2hex(random_bytes(4)).'@example.com', 'secret', [$role], $name));
    }

    private function eventCount(string $number): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM order_event e JOIN sales_order o ON o.id = e.order_id WHERE o.number = ?', [$number]);
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
