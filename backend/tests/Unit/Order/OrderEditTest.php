<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Order;

use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Order\Channel;
use Kanso\Core\Internal\Domain\Order\NewOrderLine;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderChangeRefused;
use Kanso\Core\Internal\Domain\Order\OrderCustomer;
use Kanso\Core\Internal\Domain\Order\OrderEdit;
use Kanso\Core\Internal\Domain\Order\OrderEvent;
use Kanso\Core\Internal\Domain\Order\OrderLine;
use Kanso\Core\Internal\Domain\Order\OrderStatus;
use Kanso\Core\Internal\Domain\Order\Transition;
use PHPUnit\Framework\TestCase;

/**
 * Editing an order before fulfillment and cancelling some of its units, on
 * the order's side (ADR-0011). The stock level's side is OrderStock's,
 * tested against MySQL in OrderEditApiTest.
 */
final class OrderEditTest extends TestCase
{
    private \DateTimeImmutable $now;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-28 08:00:00', new \DateTimeZone('UTC'));
        $this->actor = new Actor('user-1', 'Ops');
    }

    public function testAnEditChangesQuantitiesRemovesAndAddsLinesAndRecomputesTheTotal(): void
    {
        $order = $this->order([3, 2]);
        [$tee, $socks] = $order->lines();
        $version = $order->version();

        $event = $order->edit(new OrderEdit(
            quantities: [['line' => $tee, 'quantity' => 5]],
            removed: [$socks],
            added: [new NewOrderLine($this->product('CAP'), 'Cap', 1, 12_345)],
        ), $this->actor, $this->now);

        self::assertNotNull($event);
        self::assertSame(['TEE', 'CAP'], array_map(static fn (OrderLine $line): string => $line->skuCode(), $order->lines()));
        self::assertSame([1, 3], array_map(static fn (OrderLine $line): int => $line->position(), $order->lines()), 'A new line takes the next position; a removed one leaves a gap.');
        self::assertSame(5 * 19_950 + 12_345, $order->total()->amount);
        self::assertSame(5 * 19_950, $tee->lineTotal()->amount);
        self::assertSame($version, $order->version(), 'The version is Doctrine\'s to move, at flush.');

        self::assertSame(OrderEvent::EDITED, $event->type());
        self::assertSame([
            'lines' => [
                ['position' => 1, 'sku' => 'TEE', 'name' => 'TEE', 'quantity' => 3, 'cancelledQuantity' => 0, 'unitPrice' => 19_950],
                ['position' => 2, 'sku' => 'SOCKS', 'name' => 'SOCKS', 'quantity' => 2, 'cancelledQuantity' => 0, 'unitPrice' => 19_950],
            ],
            'total' => 5 * 19_950,
        ], $event->before());
        self::assertSame([
            'lines' => [
                ['position' => 1, 'sku' => 'TEE', 'name' => 'TEE', 'quantity' => 5, 'cancelledQuantity' => 0, 'unitPrice' => 19_950],
                ['position' => 3, 'sku' => 'CAP', 'name' => 'Cap', 'quantity' => 1, 'cancelledQuantity' => 0, 'unitPrice' => 12_345],
            ],
            'total' => 5 * 19_950 + 12_345,
        ], $event->after());
    }

    public function testAnEditOfTheCustomerRecordsOnlyWhatChanged(): void
    {
        $order = $this->order([1]);
        $address = ['name' => null, 'line1' => 'Nygatan 2', 'line2' => null, 'postalCode' => '222 33', 'city' => 'Lund', 'region' => null, 'countryCode' => 'SE', 'phone' => null];

        $event = $order->edit(new OrderEdit(customerName: 'Anna', changeEmail: true, customerEmail: 'anna@example.com', shippingAddress: $address, changeBilling: true, billingAddress: null), $this->actor, $this->now);

        self::assertNotNull($event);
        self::assertSame(['customerEmail' => null, 'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE']], $event->before(), 'The name and the billing address did not change.');
        self::assertSame(['customerEmail' => 'anna@example.com', 'shippingAddress' => $address], $event->after());
        self::assertSame(['anna@example.com', $address], [$order->customerEmail(), $order->shippingAddress()]);
    }

    public function testAnEditThatChangesNothingRecordsNothing(): void
    {
        $order = $this->order([3]);
        $events = \count($order->events());

        self::assertNull($order->edit(new OrderEdit(quantities: [['line' => $order->lines()[0], 'quantity' => 3]], customerName: 'Anna'), $this->actor, $this->now));
        self::assertCount($events, $order->events());
    }

    public function testEditableBeforePickingStartsAndNotOnceAnythingShipped(): void
    {
        $edit = fn (Order $order): ?OrderEvent => $order->edit(new OrderEdit(customerName: 'Someone else'), $this->actor, $this->now);

        $confirmed = $this->confirmed([2]);
        self::assertTrue($confirmed->canEdit());
        $confirmed->apply(Transition::Allocate, $this->actor, $this->now);
        self::assertTrue($confirmed->canEdit());
        $confirmed->apply(Transition::Hold, $this->actor, $this->now);
        self::assertTrue($confirmed->canEdit(), 'On hold from allocated.');
        $confirmed->apply(Transition::Release, $this->actor, $this->now);
        $confirmed->apply(Transition::StartPicking, $this->actor, $this->now);
        self::assertFalse($confirmed->canEdit());
        $this->assertRefused(OrderChangeRefused::NOT_EDITABLE, fn () => $edit($confirmed));

        $picking = $this->confirmed([2]);
        foreach ([Transition::Allocate, Transition::StartPicking, Transition::Hold] as $step) {
            $picking->apply($step, $this->actor, $this->now);
        }
        self::assertFalse($picking->canEdit(), 'On hold from picking.');

        $partly = $this->confirmed([2]);
        $partly->ship([['line' => $partly->lines()[0], 'quantity' => 1]], null, null, $this->now, $this->actor, $this->now);
        self::assertFalse($partly->canEdit());
        $this->assertRefused(OrderChangeRefused::NOT_EDITABLE, fn () => $edit($partly));

        $cancelled = $this->order([1]);
        $cancelled->apply(Transition::Cancel, $this->actor, $this->now);
        self::assertFalse($cancelled->canEdit());
    }

    public function testAQuantityCannotGoBelowWhatWasCancelled(): void
    {
        $order = $this->order([5]);
        $tee = $order->lines()[0];
        $order->cancelUnits([['line' => $tee, 'quantity' => 2]], null, $this->actor, $this->now);

        $this->assertRefused(OrderChangeRefused::BELOW_DONE, fn () => $order->edit(new OrderEdit(quantities: [['line' => $tee, 'quantity' => 1]]), $this->actor, $this->now));

        $order->edit(new OrderEdit(quantities: [['line' => $tee, 'quantity' => 4]]), $this->actor, $this->now);
        self::assertSame([4, 2, 2], [$tee->quantity(), $tee->cancelledQuantity(), $tee->remainingQuantity()]);
        self::assertSame(2 * 19_950, $order->total()->amount, 'Only the units not cancelled are charged.');
    }

    public function testAnEditCannotLeaveNothingToShip(): void
    {
        $order = $this->order([1]);

        $this->assertRefused(OrderChangeRefused::NOTHING_LEFT, fn () => $order->edit(new OrderEdit(removed: [$order->lines()[0]]), $this->actor, $this->now));
    }

    public function testAPartialCancelReleasesItsUnitsAndLowersTheTotal(): void
    {
        $order = $this->confirmed([5, 2]);
        [$tee, $socks] = $order->lines();

        $released = $order->cancelUnits([['line' => $tee, 'quantity' => 2]], 'Out of stock', $this->actor, $this->now);

        self::assertSame([['line' => $tee, 'released' => 2]], $released);
        self::assertSame([5, 3, 0, 2, 3], [$tee->quantity(), $tee->reservedQuantity(), $tee->shippedQuantity(), $tee->cancelledQuantity(), $tee->remainingQuantity()]);
        self::assertSame(3 * 19_950, $tee->lineTotal()->amount);
        self::assertSame(5 * 19_950, $order->total()->amount);
        self::assertSame(OrderStatus::Confirmed, $order->status());
        self::assertSame([2, 0], [$socks->reservedQuantity(), $socks->cancelledQuantity()]);

        $event = $this->lastEvent($order);
        self::assertSame(OrderEvent::LINES_CANCELLED, $event->type());
        self::assertSame(['lines' => [['position' => 1, 'sku' => 'TEE', 'cancelledQuantity' => 0]], 'total' => 7 * 19_950], $event->before());
        self::assertSame(['lines' => [['position' => 1, 'sku' => 'TEE', 'name' => 'TEE', 'cancelled' => 2, 'cancelledQuantity' => 2]], 'total' => 5 * 19_950, 'reason' => 'Out of stock'], $event->after());
    }

    public function testCancellingEveryUnitByPartsCancelsTheOrderThroughTheStateMachine(): void
    {
        $order = $this->confirmed([3, 2]);
        [$tee, $socks] = $order->lines();
        $order->cancelUnits([['line' => $tee, 'quantity' => 3]], null, $this->actor, $this->now);

        $order->cancelUnits([['line' => $socks, 'quantity' => 2]], null, $this->actor, $this->now);

        self::assertSame(OrderStatus::Cancelled, $order->status());
        self::assertSame(0, $order->total()->amount);
        $event = $this->lastEvent($order);
        self::assertSame([OrderEvent::TRANSITION, 'cancel'], [$event->type(), $event->transition()]);
        self::assertFalse($order->canCancelUnits());
    }

    public function testCancellingTheRestOfAPartlyShippedOrderShipsIt(): void
    {
        $order = $this->confirmed([3, 2]);
        [$tee, $socks] = $order->lines();
        $order->ship([['line' => $tee, 'quantity' => 3], ['line' => $socks, 'quantity' => 1]], null, null, $this->now, $this->actor, $this->now);

        $order->cancelUnits([['line' => $socks, 'quantity' => 1]], null, $this->actor, $this->now);

        self::assertSame(OrderStatus::Shipped, $order->status());
        self::assertSame([0, 1, 1], [$socks->reservedQuantity(), $socks->shippedQuantity(), $socks->cancelledQuantity()]);
        self::assertSame(3 * 19_950 + 19_950, $order->total()->amount, 'What shipped is still charged.');
        $event = $this->lastEvent($order);
        self::assertSame([OrderEvent::TRANSITION, 'ship', 'shipped'], [$event->type(), $event->transition(), $event->after()['status'] ?? null]);
    }

    public function testShippedUnitsCannotBeCancelled(): void
    {
        $order = $this->confirmed([3]);
        $tee = $order->lines()[0];
        $order->ship([['line' => $tee, 'quantity' => 2]], null, null, $this->now, $this->actor, $this->now);

        $this->assertRefused(OrderChangeRefused::EXCEEDS_REMAINING, fn () => $order->cancelUnits([['line' => $tee, 'quantity' => 2]], null, $this->actor, $this->now));
        self::assertSame([1, 2, 0], [$tee->reservedQuantity(), $tee->shippedQuantity(), $tee->cancelledQuantity()], 'A refused cancel changes nothing.');
    }

    public function testNothingIsCancelledOnceTheOrderShippedOrWasCancelled(): void
    {
        $shipped = $this->confirmed([1]);
        $shipped->ship([['line' => $shipped->lines()[0], 'quantity' => 1]], null, null, $this->now, $this->actor, $this->now);
        self::assertFalse($shipped->canCancelUnits());
        $this->assertRefused(OrderChangeRefused::NOT_CANCELLABLE, fn () => $shipped->cancelUnits([['line' => $shipped->lines()[0], 'quantity' => 1]], null, $this->actor, $this->now));

        $cancelled = $this->order([1]);
        $cancelled->apply(Transition::Cancel, $this->actor, $this->now);
        $this->assertRefused(OrderChangeRefused::NOT_CANCELLABLE, fn () => $cancelled->cancelUnits([['line' => $cancelled->lines()[0], 'quantity' => 1]], null, $this->actor, $this->now));
    }

    public function testThePartlyShippedRestOfAnOrderOnHoldIsNotCancelledUntilItIsReleased(): void
    {
        $order = $this->confirmed([2]);
        $tee = $order->lines()[0];
        $order->ship([['line' => $tee, 'quantity' => 1]], null, null, $this->now, $this->actor, $this->now);
        $order->apply(Transition::Hold, $this->actor, $this->now);

        $this->assertRefused(OrderChangeRefused::RELEASE_FIRST, fn () => $order->cancelUnits([['line' => $tee, 'quantity' => 1]], null, $this->actor, $this->now));

        $order->apply(Transition::Release, $this->actor, $this->now);
        $order->cancelUnits([['line' => $tee, 'quantity' => 1]], null, $this->actor, $this->now);
        self::assertSame(OrderStatus::Shipped, $order->status());
    }

    public function testAPendingOrderCanBeCancelledInPartsAndConfirmedForTheRest(): void
    {
        $order = $this->order([4]);
        $tee = $order->lines()[0];
        $order->cancelUnits([['line' => $tee, 'quantity' => 1]], null, $this->actor, $this->now);
        $order->apply(Transition::Confirm, $this->actor, $this->now);

        $tee->markReserved();

        self::assertSame([4, 3, 1], [$tee->quantity(), $tee->reservedQuantity(), $tee->cancelledQuantity()], 'Only the units not cancelled are reserved.');
    }

    public function testShipmentsCountCancelledUnitsAsNotLeft(): void
    {
        $order = $this->confirmed([3]);
        $tee = $order->lines()[0];
        $order->cancelUnits([['line' => $tee, 'quantity' => 1]], null, $this->actor, $this->now);

        $order->ship([['line' => $tee, 'quantity' => 2]], null, null, $this->now, $this->actor, $this->now);

        self::assertSame(OrderStatus::Shipped, $order->status(), 'Two shipped and one cancelled: nothing left.');
    }

    private function product(string $sku): Product
    {
        return new Product($sku, $sku, null, null, $this->now);
    }

    /** @param list<int> $quantities one line each: TEE, SOCKS, … at 199.50 */
    private function order(array $quantities): Order
    {
        $skus = ['TEE', 'SOCKS', 'CAP'];

        return Order::place(
            '10001',
            new Channel('manual', 'Manual', 'manual', 'SEK', $this->now),
            'SEK',
            new Location('WH1', 'Main', new Address(), $this->now),
            new OrderCustomer(null, 'Anna', null, ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'], null),
            array_map(fn (int $quantity, int $index): NewOrderLine => new NewOrderLine($this->product($skus[$index]), $skus[$index], $quantity, 19_950), $quantities, array_keys($quantities)),
            $this->now,
            $this->actor,
            $this->now,
        );
    }

    /**
     * Confirmed, and every line reserved, as OrderStock leaves it.
     *
     * @param list<int> $quantities
     */
    private function confirmed(array $quantities): Order
    {
        $order = $this->order($quantities);
        $order->apply(Transition::Confirm, $this->actor, $this->now);
        array_map(static fn (OrderLine $line) => $line->markReserved(), $order->lines());

        return $order;
    }

    private function lastEvent(Order $order): OrderEvent
    {
        $events = $order->events();
        $event = end($events);
        self::assertNotFalse($event);

        return $event;
    }

    private function assertRefused(string $reason, callable $change): void
    {
        try {
            $change();
            self::fail(\sprintf('Expected the change to be refused (%s).', $reason));
        } catch (OrderChangeRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
