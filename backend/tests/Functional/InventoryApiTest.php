<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Doctrine\DBAL\Connection;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Stock adjustments and the movement history, through the API and MySQL. */
final class InventoryApiTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;
    private string $productId;
    private string $locationId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->signInAs(Role::OPERATOR);

        $this->productId = (string) $this->api('POST', '/api/products', ['sku' => 'TEE-'.bin2hex(random_bytes(3)), 'name' => 'Tee'])['id'];
        $this->locationId = (string) $this->api('POST', '/api/locations', ['code' => 'WH-'.bin2hex(random_bytes(3)), 'name' => 'Main'])['id'];
    }

    public function testAnAdjustmentRecordsWhoWhatWhenBeforeAndAfter(): void
    {
        $movement = $this->adjust(['delta' => 12, 'reason' => 'received', 'note' => 'PO-1001', 'expectedVersion' => 0]);

        self::assertSame(201, $this->responseStatus());
        self::assertSame('adjustment', $movement['type']);
        self::assertSame('received', $movement['reason']);
        self::assertSame('PO-1001', $movement['note']);
        self::assertSame([12, 0, 12, 0, 0], [$movement['onHandChange'], $movement['onHandBefore'], $movement['onHandAfter'], $movement['reservedBefore'], $movement['reservedAfter']]);
        self::assertStringStartsWith('operator-', $movement['actorName']);
        self::assertNotSame('', $movement['actorId']);
        self::assertNotNull($movement['occurredAt']);

        $level = $this->level();
        self::assertSame([12, 0, 12, 1], [$level['onHand'], $level['reserved'], $level['available'], $level['version']]);
    }

    public function testTheHistoryIsNewestFirstAndAddsUpToTheLevel(): void
    {
        $this->adjust(['delta' => 10, 'reason' => 'received', 'expectedVersion' => 0]);
        $this->adjust(['delta' => -3, 'reason' => 'damaged', 'expectedVersion' => 1]);
        $this->adjust(['onHand' => 6, 'reason' => 'count', 'expectedVersion' => 2]);

        $history = $this->api('GET', '/api/inventory-movements?product='.$this->productId);

        self::assertSame(3, $history['totalItems']);
        self::assertSame(['count', 'damaged', 'received'], array_column($history['member'], 'reason'));
        self::assertSame([-1, -3, 10], array_column($history['member'], 'onHandChange'));
        self::assertSame(6, $this->level()['onHand']);
        self::assertSame(array_sum(array_column($history['member'], 'onHandChange')), $this->level()['onHand']);
    }

    public function testAStaleVersionIsA409AndNothingIsWritten(): void
    {
        $this->adjust(['delta' => 10, 'reason' => 'received', 'expectedVersion' => 0]);

        $problem = $this->adjust(['onHand' => 4, 'reason' => 'count', 'expectedVersion' => 0]);

        self::assertSame(409, $this->responseStatus());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertStringContainsString('version 1, you saw 0', $problem['detail']);
        self::assertSame(10, $this->level()['onHand']);
        self::assertSame(1, $this->movementCount());
    }

    public function testARefusedAdjustmentLeavesNoLevelAndNoMovement(): void
    {
        $problem = $this->adjust(['delta' => -5, 'reason' => 'lost', 'expectedVersion' => 0]);

        self::assertSame(422, $this->responseStatus());
        self::assertSame('below_zero', $problem['violations'][0]['code']);

        // The level created for the attempt was rolled back with it.
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM inventory_level l JOIN product p ON p.id = l.product_id WHERE p.sku = (SELECT sku FROM product WHERE id = ?)', [$this->binary($this->productId)]));
        self::assertSame(0, $this->movementCount());
    }

    public function testTheVersionToExpectMustBeSent(): void
    {
        $problem = $this->adjust(['delta' => 5, 'reason' => 'received']);

        self::assertSame(422, $this->responseStatus());
        self::assertSame('expectedVersion', $problem['violations'][0]['path']);
    }

    public function testAViewerCanReadStockButNotAdjustIt(): void
    {
        $this->adjust(['delta' => 5, 'reason' => 'received', 'expectedVersion' => 0]);
        $this->signInAs(Role::VIEWER);

        self::assertSame(5, $this->level()['onHand']);
        $this->adjust(['delta' => 5, 'reason' => 'received', 'expectedVersion' => 1]);
        self::assertSame(403, $this->responseStatus());
        self::assertSame(5, $this->level()['onHand']);
    }

    public function testProductsShowStockSummedOverLocations(): void
    {
        $second = (string) $this->api('POST', '/api/locations', ['code' => 'ST-'.bin2hex(random_bytes(3)), 'name' => 'Store'])['id'];
        $this->adjust(['delta' => 7, 'reason' => 'received', 'expectedVersion' => 0]);
        $this->adjust(['delta' => 4, 'reason' => 'received', 'expectedVersion' => 0, 'locationId' => $second]);

        $product = $this->api('GET', '/api/products/'.$this->productId);

        self::assertSame([11, 0, 11], [$product['onHand'], $product['reserved'], $product['available']]);
        self::assertSame(2, $this->api('GET', '/api/inventory-levels?product='.$this->productId)['totalItems']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function adjust(array $body): array
    {
        return $this->api('POST', '/api/stock-adjustments', $body + ['productId' => $this->productId, 'locationId' => $this->locationId]);
    }

    /** @return array<string, mixed> */
    private function level(): array
    {
        $levels = $this->api('GET', \sprintf('/api/inventory-levels?product=%s&location=%s', $this->productId, $this->locationId));
        self::assertSame(1, $levels['totalItems']);

        return $levels['member'][0];
    }

    private function movementCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM inventory_movement WHERE product_id = ?', [$this->binary($this->productId)]);
    }

    private function binary(string $uuid): string
    {
        return \Symfony\Component\Uid\Uuid::fromString($uuid)->toBinary();
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
