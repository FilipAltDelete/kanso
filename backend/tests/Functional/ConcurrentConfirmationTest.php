<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Kanso\Core\Internal\Application\Catalog\CatalogService;
use Kanso\Core\Internal\Application\Inventory\InventoryService;
use Kanso\Core\Internal\Application\Order\OrderService;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Orders confirmed at the same moment, each in its own process with its own
 * MySQL connection, as PHP-FPM workers are. The in-process tests prove the
 * arithmetic; only this proves the locking, because a race needs two
 * connections.
 *
 * The data is committed (no rollback wrapper: the child processes cannot see
 * an uncommitted transaction), so the test deletes what it made.
 */
#[SkipDatabaseRollback]
final class ConcurrentConfirmationTest extends KernelTestCase
{
    private const int PROCESSES = 8;

    private Location $location;
    private Product $product;
    /** @var list<string> */
    private array $orderIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $catalog = $this->service(CatalogService::class);

        $this->location = $catalog->createLocation('RACE-'.bin2hex(random_bytes(3)), 'Race', new Address());
        $this->product = $catalog->createProduct('RACE-'.bin2hex(random_bytes(3)), 'Racing tee', null, null);
    }

    protected function tearDown(): void
    {
        $db = $this->service(Connection::class);
        $product = $this->product->id()->toBinary();
        $location = $this->location->id()->toBinary();
        $orders = array_map(static fn (string $id): string => \Symfony\Component\Uid\Uuid::fromString($id)->toBinary(), $this->orderIds);

        $db->executeStatement('DELETE FROM inventory_movement WHERE product_id = ?', [$product]);
        if ([] !== $orders) {
            $db->executeStatement('DELETE FROM order_event WHERE order_id IN (?)', [$orders], [\Doctrine\DBAL\ArrayParameterType::BINARY]);
            $db->executeStatement('DELETE FROM order_line WHERE order_id IN (?)', [$orders], [\Doctrine\DBAL\ArrayParameterType::BINARY]);
            $db->executeStatement('DELETE FROM sales_order WHERE id IN (?)', [$orders], [\Doctrine\DBAL\ArrayParameterType::BINARY]);
        }
        $db->executeStatement('DELETE FROM inventory_level WHERE product_id = ?', [$product]);
        $db->executeStatement('DELETE FROM product WHERE id = ?', [$product]);
        $db->executeStatement('DELETE FROM location WHERE id = ?', [$location]);

        parent::tearDown();
    }

    public function testTheLastUnitsGoToExactlyAsManyOrdersAsThereAreUnits(): void
    {
        $this->stock(5);
        $this->orders(self::PROCESSES);

        $results = $this->confirmAllAtOnce();

        self::assertSame(['confirmed' => 5, 'conflict:insufficient_stock' => 3], $this->tally($results), 'Eight orders of one, five in stock.');
        self::assertSame(['on_hand' => 5, 'reserved' => 5], $this->level());
        self::assertSame(5, $this->countRows("SELECT COUNT(*) FROM inventory_movement WHERE product_id = ? AND type = 'reservation'"));
        self::assertSame(5, $this->countRows('SELECT COUNT(*) FROM order_line WHERE product_id = ? AND reserved_quantity = 1'));
    }

    public function testConfirmationsThatFitAllSucceedAndNoneIsLost(): void
    {
        $this->stock(self::PROCESSES);
        $this->orders(self::PROCESSES);

        $results = $this->confirmAllAtOnce();

        // Each waits for the one before it rather than failing on a stale
        // read, and none overwrites another's reservation.
        self::assertSame(['confirmed' => self::PROCESSES], $this->tally($results));
        self::assertSame(['on_hand' => self::PROCESSES, 'reserved' => self::PROCESSES], $this->level());
    }

    private function stock(int $quantity): void
    {
        $this->service(InventoryService::class)->adjust((string) $this->product->id(), (string) $this->location->id(), $quantity, null, 'received', null, 0, new Actor('test', 'Test'));
    }

    private function orders(int $count): void
    {
        $orders = $this->service(OrderService::class);
        for ($i = 0; $i < $count; ++$i) {
            $this->orderIds[] = (string) $orders->create([
                'location' => $this->location->code(),
                'customer' => ['name' => 'Racer '.$i],
                'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'],
                'lines' => [['sku' => $this->product->sku(), 'quantity' => 1, 'unitPrice' => 100]],
            ], new Actor('test', 'Test'))->id();
        }
    }

    /** @return list<string> what each process printed */
    private function confirmAllAtOnce(): array
    {
        // Far enough ahead for every process to boot its kernel first.
        $startAt = \sprintf('%.6F', microtime(true) + 3.0);
        $env = [...getenv(), 'APP_ENV' => 'test', 'DATABASE_URL' => (string) getenv('DATABASE_URL')];
        $script = \dirname(__DIR__).'/Support/confirm-order.php';

        $running = [];
        foreach ($this->orderIds as $id) {
            $process = proc_open([\PHP_BINARY, $script, $id, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($process);
            $running[] = [$process, $pipes];
        }

        $results = [];
        foreach ($running as [$process, $pipes]) {
            $out = trim((string) stream_get_contents($pipes[1]));
            $err = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $err);
            $results[] = $out;
        }

        return $results;
    }

    /**
     * @param list<string> $results
     *
     * @return array<string, int>
     */
    private function tally(array $results): array
    {
        $tally = array_count_values($results);
        ksort($tally);

        return $tally;
    }

    /** @return array{on_hand: int, reserved: int} */
    private function level(): array
    {
        $row = $this->service(Connection::class)->fetchAssociative(
            'SELECT on_hand, reserved FROM inventory_level WHERE product_id = ? AND location_id = ?',
            [$this->product->id()->toBinary(), $this->location->id()->toBinary()],
        );
        self::assertIsArray($row);

        return ['on_hand' => (int) $row['on_hand'], 'reserved' => (int) $row['reserved']];
    }

    private function countRows(string $sql): int
    {
        return (int) $this->service(Connection::class)->fetchOne($sql, [$this->product->id()->toBinary()]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
