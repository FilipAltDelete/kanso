<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Inventory;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Inventory\InventoryService;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Inventory\AdjustmentReason;
use Kanso\Core\Internal\Domain\Inventory\InventoryMovement;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Tests\Support\InMemoryInventory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Manual adjustments: each one a level change and its movement, together or not at all. */
#[CoversClass(InventoryService::class)]
final class InventoryServiceTest extends TestCase
{
    private InMemoryInventory $store;
    private InventoryService $service;
    private MockClock $clock;
    private string $product;
    private string $location;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->store = new InMemoryInventory();
        $this->clock = new MockClock('2026-09-25 10:00:00', 'UTC');
        $this->service = new InventoryService($this->store->products, $this->store->locations, $this->store, $this->store, $this->clock);
        $this->actor = new Actor('0199aaaa-0000-7000-8000-000000000001', 'Ops Person');

        $product = new Product('TEE-1', 'Tee', null, null, $this->clock->now());
        $location = new Location('WH1', 'Main', new Address(), $this->clock->now());
        $this->store->products->add($product);
        $this->store->locations->add($location);
        $this->product = $product->id()->toRfc4122();
        $this->location = $location->id()->toRfc4122();
    }

    public function testTheFirstAdjustmentCreatesTheLevelAndRecordsWhoWhatWhenBeforeAndAfter(): void
    {
        $movement = $this->adjust(delta: 12, reason: 'received', note: ' PO-1001 ', expectedVersion: 0);

        self::assertCount(1, $this->store->levels);
        self::assertSame(12, $this->store->levels[0]->onHand());
        self::assertSame([$movement], $this->store->movements);

        self::assertSame(AdjustmentReason::Received, $movement->reason());
        self::assertSame('PO-1001', $movement->note());
        self::assertEquals($this->actor, $movement->actor());
        self::assertEquals($this->clock->now(), $movement->occurredAt());
        self::assertSame([0, 12, 0, 0], [
            $movement->change()->onHandBefore,
            $movement->change()->onHandAfter,
            $movement->change()->reservedBefore,
            $movement->change()->reservedAfter,
        ]);
    }

    public function testEveryAdjustmentAddsOneMovementAndTheHistoryAddsUpToTheLevel(): void
    {
        $this->adjust(delta: 10, expectedVersion: 0);
        $this->bumpVersion();
        $this->adjust(delta: -3, reason: 'damaged', expectedVersion: 2);
        $this->bumpVersion();
        $this->adjust(counted: 5, reason: 'count', expectedVersion: 3);

        $changes = array_map(static fn (InventoryMovement $movement): int => $movement->change()->onHandDelta(), $this->store->movements);

        self::assertSame([10, -3, -2], $changes);
        self::assertSame(array_sum($changes), $this->store->levels[0]->onHand(), 'Replaying the history gives the current level.');

        // Each movement starts where the one before it ended.
        foreach (\array_slice($this->store->movements, 1) as $index => $movement) {
            self::assertSame($this->store->movements[$index]->change()->onHandAfter, $movement->change()->onHandBefore);
        }
    }

    public function testAStaleVersionIsAConflictAndChangesNothing(): void
    {
        $this->adjust(delta: 10, expectedVersion: 0);

        try {
            // The operator still has the page from before the first adjustment.
            $this->adjust(counted: 4, reason: 'count', expectedVersion: 0);
            self::fail('A stale version must not be applied.');
        } catch (Conflict $conflict) {
            self::assertStringContainsString('you saw 0', $conflict->getMessage());
        }

        self::assertSame(10, $this->store->levels[0]->onHand());
        self::assertCount(1, $this->store->movements);
    }

    public function testExpectingALevelThatDoesNotExistIsAConflict(): void
    {
        $this->expectException(Conflict::class);

        $this->adjust(delta: 1, expectedVersion: 3);
    }

    public function testLosingTheRaceAtCommitIsAConflictAndRollsBack(): void
    {
        $this->store->loseNextRace = true;

        try {
            $this->adjust(delta: 5, expectedVersion: 0);
            self::fail('A lost optimistic lock must not look like success.');
        } catch (Conflict) {
        }

        self::assertSame([], $this->store->levels);
        self::assertSame([], $this->store->movements);
    }

    public function testARefusedChangeWritesNoMovement(): void
    {
        try {
            $this->adjust(delta: -1, reason: 'lost', expectedVersion: 0);
            self::fail('Stock cannot go below zero.');
        } catch (ValidationFailed $failed) {
            self::assertSame([['path' => 'delta', 'message' => 'On hand cannot go below zero (it would be -1).', 'code' => 'below_zero']], $failed->violations());
        }

        self::assertSame([], $this->store->levels, 'The level created for the attempt was rolled back with it.');
        self::assertSame([], $this->store->movements);
    }

    public function testDeltaAndCountAreExclusive(): void
    {
        $this->assertViolation('delta', fn () => $this->adjust(delta: 1, counted: 1, expectedVersion: 0));
        $this->assertViolation('delta', fn () => $this->adjust(expectedVersion: 0));
    }

    public function testTheReasonMustBeKnownAndOtherNeedsANote(): void
    {
        $this->assertViolation('reason', fn () => $this->adjust(delta: 1, reason: 'theft', expectedVersion: 0));
        $this->assertViolation('note', fn () => $this->adjust(delta: 1, reason: 'other', note: '  ', expectedVersion: 0));

        $movement = $this->adjust(delta: 1, reason: 'other', note: 'Sample for a photo shoot', expectedVersion: 0);
        self::assertSame(AdjustmentReason::Other, $movement->reason());
    }

    public function testUnknownProductsAndLocationsAreNamed(): void
    {
        try {
            $this->service->adjust('nope', 'nope', 1, null, 'received', null, 0, $this->actor);
            self::fail('Expected validation to fail.');
        } catch (ValidationFailed $failed) {
            self::assertSame(['productId', 'locationId'], array_column($failed->violations(), 'path'));
        }
    }

    private function adjust(?int $delta = null, ?int $counted = null, string $reason = 'correction', ?string $note = null, int $expectedVersion = 0): InventoryMovement
    {
        return $this->service->adjust($this->product, $this->location, $delta, $counted, $reason, $note, $expectedVersion, $this->actor);
    }

    /** Doctrine raises the version on flush; the in-memory store has no flush, so the test does it. */
    private function bumpVersion(): void
    {
        $level = $this->store->levels[0];
        $property = new \ReflectionProperty($level, 'version');
        $property->setValue($level, $level->version() + 1);
    }

    private function assertViolation(string $path, callable $call): void
    {
        try {
            $call();
            self::fail(\sprintf('Expected a violation at "%s".', $path));
        } catch (ValidationFailed $failed) {
            self::assertContains($path, array_column($failed->violations(), 'path'));
        }
    }
}
