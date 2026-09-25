<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Inventory;

use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Inventory\InventoryLevel;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Inventory\StockRuleViolated;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The stock arithmetic: available = on hand − reserved, and 0 ≤ reserved ≤ on hand always. */
#[CoversClass(InventoryLevel::class)]
final class InventoryLevelTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-25 10:00:00');
    }

    public function testANewLevelIsEmpty(): void
    {
        $level = $this->level();

        self::assertSame([0, 0, 0], [$level->onHand(), $level->reserved(), $level->available()]);
    }

    public function testAdjustingAddsAndRemovesAndReportsBeforeAndAfter(): void
    {
        $level = $this->level();

        $in = $level->adjustBy(10, $this->now);
        $out = $level->adjustBy(-3, $this->now);

        self::assertSame([0, 10, 10], [$in->onHandBefore, $in->onHandAfter, $in->onHandDelta()]);
        self::assertSame([10, 7, -3], [$out->onHandBefore, $out->onHandAfter, $out->onHandDelta()]);
        self::assertSame(7, $level->onHand());
        self::assertSame(7, $level->available());
    }

    public function testAvailableIsOnHandMinusReserved(): void
    {
        $level = $this->level(onHand: 10);

        $change = $level->reserve(4, $this->now);

        self::assertSame(10, $level->onHand(), 'Reserving does not move stock off the shelf.');
        self::assertSame(4, $level->reserved());
        self::assertSame(6, $level->available());
        self::assertSame([0, 4, 0], [$change->reservedBefore, $change->reservedAfter, $change->onHandDelta()]);
    }

    public function testReleasingGivesReservedStockBackToAvailable(): void
    {
        $level = $this->level(onHand: 10);
        $level->reserve(4, $this->now);

        $level->release(3, $this->now);

        self::assertSame([10, 1, 9], [$level->onHand(), $level->reserved(), $level->available()]);
    }

    public function testACountSetsOnHandAndAMatchingCountIsStillAChange(): void
    {
        $level = $this->level(onHand: 10);

        $short = $level->countAs(8, $this->now);
        $matching = $level->countAs(8, $this->now);

        self::assertSame(-2, $short->onHandDelta());
        self::assertSame([8, 8, 0], [$matching->onHandBefore, $matching->onHandAfter, $matching->onHandDelta()]);
    }

    public function testStockCannotGoBelowZero(): void
    {
        $level = $this->level(onHand: 5);

        $this->assertRule(StockRuleViolated::BELOW_ZERO, fn () => $level->adjustBy(-6, $this->now));
        $this->assertRule(StockRuleViolated::BELOW_ZERO, fn () => $level->countAs(-1, $this->now));
        self::assertSame(5, $level->onHand(), 'A refused change leaves the level as it was.');
    }

    public function testReservedStockCannotBeAdjustedAway(): void
    {
        $level = $this->level(onHand: 10);
        $level->reserve(6, $this->now);

        $level->adjustBy(-4, $this->now); // down to exactly the reserved 6: allowed, 0 available

        self::assertSame([6, 6, 0], [$level->onHand(), $level->reserved(), $level->available()]);
        $this->assertRule(StockRuleViolated::BELOW_RESERVED, fn () => $level->adjustBy(-1, $this->now));
        $this->assertRule(StockRuleViolated::BELOW_RESERVED, fn () => $level->countAs(5, $this->now));
        self::assertSame(6, $level->onHand());
    }

    public function testNoMoreThanIsAvailableCanBeReserved(): void
    {
        $level = $this->level(onHand: 5);
        $level->reserve(3, $this->now);

        $this->assertRule(StockRuleViolated::INSUFFICIENT_AVAILABLE, fn () => $level->reserve(3, $this->now));
        self::assertSame(3, $level->reserved());
    }

    public function testNoMoreThanIsReservedCanBeReleased(): void
    {
        $level = $this->level(onHand: 5);
        $level->reserve(2, $this->now);

        $this->assertRule(StockRuleViolated::OVER_RELEASE, fn () => $level->release(3, $this->now));
    }

    /** @return iterable<string, array{int}> */
    public static function notPositive(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('notPositive')]
    public function testReservationsAreForAtLeastOne(int $quantity): void
    {
        $level = $this->level(onHand: 5);

        $this->assertRule(StockRuleViolated::NOT_POSITIVE, fn () => $level->reserve($quantity, $this->now));
        $this->assertRule(StockRuleViolated::NOT_POSITIVE, fn () => $level->release($quantity, $this->now));
    }

    public function testAnAdjustmentOfNothingIsRefused(): void
    {
        $this->assertRule(StockRuleViolated::NO_CHANGE, fn () => $this->level(onHand: 5)->adjustBy(0, $this->now));
    }

    public function testStockHasASanityCeiling(): void
    {
        $level = $this->level(onHand: InventoryLevel::MAX_QUANTITY);

        $this->assertRule(StockRuleViolated::TOO_LARGE, fn () => $level->adjustBy(1, $this->now));
    }

    public function testAChangeStampsTheLevel(): void
    {
        $level = $this->level();
        $later = $this->now->modify('+1 hour');

        $level->adjustBy(1, $later);

        self::assertEquals($later, $level->updatedAt());
    }

    private function level(int $onHand = 0): InventoryLevel
    {
        $level = new InventoryLevel(
            new Product('TEE-1', 'Tee', null, 180, $this->now),
            new Location('WH1', 'Main', new Address(), $this->now),
            $this->now,
        );
        if ($onHand > 0) {
            $level->adjustBy($onHand, $this->now);
        }

        return $level;
    }

    private function assertRule(string $rule, callable $change): void
    {
        try {
            $change();
            self::fail(\sprintf('Expected the "%s" rule to refuse the change.', $rule));
        } catch (StockRuleViolated $violation) {
            self::assertSame($rule, $violation->rule);
        }
    }
}
