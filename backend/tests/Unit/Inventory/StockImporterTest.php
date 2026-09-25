<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Inventory;

use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Inventory\StockImporter;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Inventory\AdjustmentReason;
use Kanso\Core\Internal\Domain\Inventory\InventoryLevel;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Tests\Support\InMemoryInventory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Counted quantities from a CSV file: each row a count with its movement, the same file twice a no-op. */
#[CoversClass(StockImporter::class)]
final class StockImporterTest extends TestCase
{
    private InMemoryInventory $store;
    private StockImporter $importer;
    private MockClock $clock;
    private Actor $actor;
    private Product $tee;
    private Product $mug;
    private Location $main;

    protected function setUp(): void
    {
        $this->store = new InMemoryInventory();
        $this->clock = new MockClock('2026-09-25 10:00:00', 'UTC');
        $this->importer = new StockImporter($this->store->products, $this->store->locations, $this->store, $this->store, $this->clock);
        $this->actor = new Actor('0199aaaa-0000-7000-8000-000000000001', 'Ops Person');

        $this->tee = new Product('TEE-1', 'Tee', null, null, $this->clock->now());
        $this->mug = new Product('MUG-1', 'Mug', null, null, $this->clock->now());
        $this->main = new Location('WH1', 'Main', new Address(), $this->clock->now());
        $this->store->products->add($this->tee);
        $this->store->products->add($this->mug);
        $this->store->locations->add($this->main);
    }

    public function testEachRowSetsOnHandAndRecordsACountMovement(): void
    {
        $result = $this->importer->import("sku;location;quantity\nTEE-1;WH1;12\nMUG-1;WH1;0\n", false, $this->actor);

        self::assertSame([false, 2, 1, 1, 0], [$result->dryRun, $result->rows, $result->changed, $result->unchanged, $result->failed]);
        self::assertCount(1, $this->store->levels, 'A zero count where there was no stock creates no level.');
        self::assertSame(12, $this->store->levels[0]->onHand());

        [$movement] = $this->store->movements;
        self::assertSame(AdjustmentReason::Count, $movement->reason());
        self::assertSame([0, 12], [$movement->change()->onHandBefore, $movement->change()->onHandAfter]);
        self::assertEquals($this->actor, $movement->actor());
    }

    public function testTheSameFileAgainChangesNothing(): void
    {
        $csv = "sku,location,quantity\nTEE-1,WH1,12\nMUG-1,WH1,3\n";
        $this->importer->import($csv, false, $this->actor);

        $again = $this->importer->import($csv, false, $this->actor);

        self::assertSame([0, 2], [$again->changed, $again->unchanged]);
        self::assertCount(2, $this->store->movements);
        self::assertSame([1, 1], array_map(static fn (InventoryLevel $level): int => $level->version(), $this->store->levels));
    }

    public function testAPreviewCountsButWritesNothing(): void
    {
        $result = $this->importer->import("sku,location,quantity\nTEE-1,WH1,12\n", true, $this->actor);

        self::assertSame([true, 1, 0], [$result->dryRun, $result->changed, $result->unchanged]);
        self::assertSame([], $this->store->levels);
        self::assertSame([], $this->store->movements);
    }

    public function testBadRowsAreReportedAndSkippedAndTheRestIsImported(): void
    {
        $csv = "sku,location,quantity\nTEE-1,WH1,5\nNOPE,WH1,1\nMUG-1,WH9,1\nMUG-1,WH1,-2\nMUG-1,WH1,1.5\n,WH1,1\nTEE-1,WH1,6\n";

        $result = $this->importer->import($csv, false, $this->actor);

        self::assertSame([1, 6], [$result->changed, $result->failed]);
        self::assertSame(
            [[3, 'sku', 'not_found'], [4, 'location', 'not_found'], [5, 'quantity', 'integer'], [6, 'quantity', 'integer'], [7, 'sku', 'required'], [8, 'row', 'duplicate']],
            array_map(static fn (array $error): array => [$error['row'], $error['field'], $error['code']], $result->errors),
        );
        self::assertSame(['TEE-1', 'WH1'], [$result->errors[5]['sku'], $result->errors[5]['location']]);
        self::assertSame(5, $this->store->levels[0]->onHand());
    }

    public function testACountBelowWhatIsReservedIsRefused(): void
    {
        $level = new InventoryLevel($this->tee, $this->main, $this->clock->now());
        $level->countAs(10, $this->clock->now());
        $level->reserve(4, $this->clock->now());
        $this->store->addLevel($level);

        $result = $this->importer->import("sku,location,quantity\nTEE-1,WH1,3\n", true, $this->actor);
        self::assertSame([0, 1, 'below_reserved'], [$result->changed, $result->failed, $result->errors[0]['code']]);

        $result = $this->importer->import("sku,location,quantity\nTEE-1,WH1,4\n", false, $this->actor);
        self::assertSame([1, 0], [$result->changed, $result->failed]);
        self::assertSame([4, 4, 0], [$level->onHand(), $level->reserved(), $level->available()]);
    }

    public function testARowThatLosesARaceFailsAloneAndLeavesNothing(): void
    {
        $this->store->loseNextRace = true;

        $result = $this->importer->import("sku,location,quantity\nTEE-1,WH1,5\nMUG-1,WH1,7\n", false, $this->actor);

        self::assertSame([1, 1], [$result->changed, $result->failed]);
        self::assertSame([2, 'row', 'conflict'], [$result->errors[0]['row'], $result->errors[0]['field'], $result->errors[0]['code']]);
        self::assertCount(1, $this->store->levels);
        self::assertSame($this->mug, $this->store->levels[0]->product());
        self::assertCount(1, $this->store->movements);
    }

    public function testAFileWithoutTheQuantityColumnIsRefusedWhole(): void
    {
        try {
            $this->importer->import("sku,location\nTEE-1,WH1\n", true, $this->actor);
            self::fail('Expected the file to be refused.');
        } catch (ValidationFailed $e) {
            self::assertSame(['header.quantity'], array_column($e->violations(), 'path'));
        }
    }
}
