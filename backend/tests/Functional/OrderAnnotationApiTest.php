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

/** Notes, tags and payment status on orders, through the API. */
final class OrderAnnotationApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $operator;
    private string $viewer;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->operator = $this->token(Role::OPERATOR, 'Olle Operator');
        $this->viewer = $this->token(Role::VIEWER, 'Vera Viewer');

        $catalog = static::getContainer()->get(CatalogService::class);
        $inventory = static::getContainer()->get(InventoryService::class);
        self::assertInstanceOf(CatalogService::class, $catalog);
        self::assertInstanceOf(InventoryService::class, $inventory);
        $location = $catalog->createLocation('WH1', 'Main', new Address());
        $product = $catalog->createProduct('TSHIRT-M', 'T-shirt, M', null, null);
        $inventory->adjust((string) $product->id(), (string) $location->id(), 100, null, 'received', null, 0, new Actor('test', 'Test'));
    }

    public function testANoteGoesInTheTimelineInAnyStatusWithoutChangingTheVersion(): void
    {
        $order = $this->create();
        $this->post('/orders/'.$order['id'].'/transitions', ['transition' => 'cancel', 'version' => 1]);

        $noted = $this->post('/orders/'.$order['id'].'/notes', ['note' => '  Customer asked to cancel by phone. ']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $noted['version'], 'a note does not make open pages stale');
        $note = end($noted['events']);
        self::assertSame('note', $note['type']);
        self::assertSame(['note' => 'Customer asked to cancel by phone.'], $note['after']);
        self::assertSame('Olle Operator', $note['actor']['name']);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM order_event e JOIN sales_order o ON o.id = e.order_id WHERE o.number = ? AND e.type = 'note'", [$order['number']]));
    }

    public function testAnEmptyNoteIsInvalid(): void
    {
        $order = $this->create();

        $this->post('/orders/'.$order['id'].'/notes', ['note' => '  ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['note' => 'required'], array_column($this->json()['violations'], 'code', 'path'));
    }

    public function testTagsAreAddedAndRemovedAndRecorded(): void
    {
        $order = $this->create();

        $tagged = $this->post('/orders/'.$order['id'].'/tags', ['add' => ['VIP', 'gift wrap']]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['gift wrap', 'VIP'], $tagged['tags']);
        self::assertSame(1, $tagged['version']);

        $tagged = $this->post('/orders/'.$order['id'].'/tags', ['add' => ['vip', 'Rush'], 'remove' => ['GIFT WRAP']]);
        self::assertSame(['Rush', 'VIP'], $tagged['tags']);
        self::assertSame(['tags' => ['gift wrap', 'VIP']], end($tagged['events'])['before']);
        self::assertSame(['tags' => ['Rush', 'VIP']], end($tagged['events'])['after']);

        $this->request('GET', '/api/orders/'.$order['id'], $this->viewer);
        self::assertSame(['Rush', 'VIP'], $this->json()['tags']);
    }

    public function testBadTagsAreReportedAtTheirPaths(): void
    {
        $order = $this->create();

        $this->post('/orders/'.$order['id'].'/tags', ['add' => ['ok', 'a,b', '', 7], 'remove' => ['OK']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['add[1]' => 'tag', 'add[2]' => 'tag', 'add[3]' => 'type', 'remove' => 'conflicting_tags'], array_column($this->json()['violations'], 'code', 'path'));

        $this->post('/orders/'.$order['id'].'/tags', []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheListFiltersByTagAndPaymentStatus(): void
    {
        $tag = 'Tag'.bin2hex(random_bytes(3));
        $a = $this->create();
        $b = $this->create();
        $c = $this->create();
        $this->post('/orders/'.$a['id'].'/tags', ['add' => [$tag, 'other']]);
        $this->post('/orders/'.$b['id'].'/tags', ['add' => [strtolower($tag)]]);
        $this->post('/orders/'.$c['id'].'/payment-status', ['paymentStatus' => 'paid', 'version' => 1]);

        $list = $this->list(['tag' => strtoupper($tag), 'sort' => 'number']);
        self::assertSame([$a['number'], $b['number']], array_column($list['member'], 'number'));
        self::assertSame(['other', $tag], $list['member'][0]['tags'], 'the list shows every tag of a filtered order');
        self::assertSame('unpaid', $list['member'][0]['paymentStatus']);

        $paid = array_column($this->list(['paymentStatus' => 'paid,refunded', 'itemsPerPage' => 500])['member'], 'number');
        self::assertContains($c['number'], $paid);
        self::assertNotContains($a['number'], $paid);

        $this->request('GET', '/api/orders?paymentStatus=free', $this->viewer);
        self::assertResponseStatusCodeSame(422);

        $this->request('GET', '/api/order-tags', $this->viewer);
        self::assertResponseIsSuccessful();
        $counts = array_column($this->json()['member'], 'orders', 'name');
        self::assertSame(2, $counts[$tag] ?? $counts[strtolower($tag)] ?? null);
    }

    public function testBulkTaggingIsAllOrNone(): void
    {
        $tag = 'Bulk'.bin2hex(random_bytes(3));
        $a = $this->create();
        $b = $this->create();
        $this->post('/orders/'.$b['id'].'/tags', ['add' => [$tag]]);

        $this->post('/orders/bulk-tags', ['orders' => [$a['id'], $b['id'], '0192f1a4-7b6e-7c3d-9a1b-2c3d4e5f6a7b'], 'add' => ['x-'.$tag]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['orders[2]' => 'unknown_order'], array_column($this->json()['violations'], 'code', 'path'));
        self::assertSame(0, $this->list(['tag' => 'x-'.$tag])['totalItems']);

        $this->post('/orders/bulk-tags', ['orders' => [$a['id'], $b['id']], 'add' => [$tag, 'x-'.$tag]]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(2, $this->list(['tag' => 'x-'.$tag])['totalItems']);
        // Each order records its own change: $a its first, $b its second.
        self::assertSame([1, 2], [$this->tagEvents($a['number']), $this->tagEvents($b['number'])]);

        $this->post('/orders/bulk-tags', ['orders' => [$a['id'], $b['id']], 'remove' => [$tag]]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->list(['tag' => $tag])['totalItems']);
    }

    public function testBulkTaggingStopsAtTheTagLimit(): void
    {
        $order = $this->create();
        $this->post('/orders/'.$order['id'].'/tags', ['add' => array_map(static fn (int $n): string => 'limit-'.$n, range(1, 20))]);
        self::assertResponseStatusCodeSame(200);

        $this->post('/orders/bulk-tags', ['orders' => [$order['id']], 'add' => ['one-more']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('too_many_tags', $this->json()['violations'][0]['code']);
    }

    public function testThePaymentStatusIsSetByHandWithTheVersion(): void
    {
        $order = $this->create();
        self::assertSame('unpaid', $order['paymentStatus']);

        $paid = $this->post('/orders/'.$order['id'].'/payment-status', ['paymentStatus' => 'authorized', 'version' => 1]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('authorized', $paid['paymentStatus']);
        self::assertSame(2, $paid['version']);
        $event = end($paid['events']);
        self::assertSame('payment_status_changed', $event['type']);
        self::assertSame(['paymentStatus' => 'unpaid'], $event['before']);
        self::assertSame(['paymentStatus' => 'authorized'], $event['after']);

        // Someone still looking at version 1.
        $this->post('/orders/'.$order['id'].'/payment-status', ['paymentStatus' => 'refunded', 'version' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_version', $this->json()['violations'][0]['code']);

        $this->post('/orders/'.$order['id'].'/payment-status', ['paymentStatus' => 'card:4111', 'version' => 2]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unknown_payment_status', $this->json()['violations'][0]['code']);
    }

    public function testAViewerCannotAnnotate(): void
    {
        $order = $this->create();

        foreach ([
            ['/orders/'.$order['id'].'/notes', ['note' => 'Hi']],
            ['/orders/'.$order['id'].'/tags', ['add' => ['x']]],
            ['/orders/bulk-tags', ['orders' => [$order['id']], 'add' => ['x']]],
            ['/orders/'.$order['id'].'/payment-status', ['paymentStatus' => 'paid', 'version' => 1]],
        ] as [$path, $body]) {
            $this->request('POST', '/api'.$path, $this->viewer, $body);
            self::assertResponseStatusCodeSame(403, $path);
        }
    }

    public function testAnUnknownOrderIsNotFound(): void
    {
        $this->post('/orders/0192f1a4-7b6e-7c3d-9a1b-2c3d4e5f6a7b/notes', ['note' => 'Hi']);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string, mixed> */
    private function create(): array
    {
        $order = $this->post('/orders', [
            'customer' => ['name' => 'Anna Andersson'],
            'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'],
            'lines' => [['sku' => 'TSHIRT-M', 'quantity' => 1, 'unitPrice' => 19_950]],
        ]);
        self::assertResponseStatusCodeSame(201);

        return $order;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $this->request('POST', '/api'.$path, $this->operator, $body);
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : $this->json();
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
    private function request(string $method, string $uri, string $token, ?array $body = null): void
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
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

    private function tagEvents(string $number): int
    {
        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM order_event e JOIN sales_order o ON o.id = e.order_id WHERE o.number = ? AND e.type = 'tags_changed'", [$number]);
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
