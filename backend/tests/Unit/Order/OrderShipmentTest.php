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
use Kanso\Core\Internal\Domain\Order\OrderCustomer;
use Kanso\Core\Internal\Domain\Order\OrderEvent;
use Kanso\Core\Internal\Domain\Order\OrderLine;
use Kanso\Core\Internal\Domain\Order\OrderStatus;
use Kanso\Core\Internal\Domain\Order\ShipmentRefused;
use Kanso\Core\Internal\Domain\Order\Transition;
use Kanso\Core\Internal\Domain\Order\TransitionNotAllowed;
use PHPUnit\Framework\TestCase;

/**
 * An order's side of a shipment: per line, reserved + shipped = quantity,
 * and the order ships when nothing is left. (The stock level's side is
 * OrderStock's, tested against MySQL in ShipmentApiTest.).
 */
final class OrderShipmentTest extends TestCase
{
    private \DateTimeImmutable $now;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-26 08:00:00', new \DateTimeZone('UTC'));
        $this->actor = new Actor('user-1', 'Ops');
    }

    public function testAPartialShipmentMovesUnitsFromReservedToShipped(): void
    {
        $order = $this->confirmedOrder([5, 2]);
        [$tee, $socks] = $order->lines();

        $shipment = $order->ship([['line' => $tee, 'quantity' => 2]], 'PostNord', '00370712345', $this->now, $this->actor, $this->now);

        self::assertSame([5, 3, 2, 3], [$tee->quantity(), $tee->reservedQuantity(), $tee->shippedQuantity(), $tee->remainingQuantity()]);
        self::assertSame([2, 0, 2], [$socks->reservedQuantity(), $socks->shippedQuantity(), $socks->remainingQuantity()]);
        self::assertSame(2, $shipment->units());
        self::assertSame(OrderStatus::Confirmed, $order->status(), 'Units are left, so the order has not shipped.');
        self::assertTrue($order->canShip());

        $events = $order->events();
        $event = end($events);
        self::assertNotFalse($event);
        self::assertSame(OrderEvent::SHIPMENT, $event->type());
        self::assertSame(['PostNord', '00370712345', [['position' => 1, 'sku' => 'TEE', 'quantity' => 2]]], [$event->after()['carrier'] ?? null, $event->after()['trackingNumber'] ?? null, $event->after()['lines'] ?? null]);
    }

    public function testTheLastShipmentShipsTheOrder(): void
    {
        $order = $this->confirmedOrder([5, 2]);
        [$tee, $socks] = $order->lines();
        $order->ship([['line' => $tee, 'quantity' => 3]], null, null, $this->now, $this->actor, $this->now);

        $order->ship([['line' => $tee, 'quantity' => 2], ['line' => $socks, 'quantity' => 2]], 'DHL', 'JD0123', $this->now, $this->actor, $this->now);

        self::assertSame(OrderStatus::Shipped, $order->status());
        foreach ($order->lines() as $line) {
            self::assertSame([0, $line->quantity(), 0], [$line->reservedQuantity(), $line->shippedQuantity(), $line->remainingQuantity()]);
        }
        self::assertCount(2, $order->shipments());
        self::assertFalse($order->canShip());
        $events = $order->events();
        $last = end($events);
        self::assertNotFalse($last);
        self::assertSame([OrderEvent::TRANSITION, 'ship', 'shipped'], [$last->type(), $last->transition(), $last->after()['status'] ?? null], 'Moved to shipped through the state machine, and recorded.');
    }

    public function testShippingFromAnyStockHoldingStatus(): void
    {
        foreach ([[], [Transition::Allocate], [Transition::Allocate, Transition::StartPicking], [Transition::Allocate, Transition::StartPicking, Transition::Pack]] as $steps) {
            $order = $this->confirmedOrder([1]);
            foreach ($steps as $step) {
                $order->apply($step, $this->actor, $this->now);
            }

            $order->ship([['line' => $order->lines()[0], 'quantity' => 1]], null, null, $this->now, $this->actor, $this->now);

            self::assertSame(OrderStatus::Shipped, $order->status());
        }
    }

    public function testALineCannotShipMoreThanItHasLeft(): void
    {
        $order = $this->confirmedOrder([3]);
        $tee = $order->lines()[0];
        $order->ship([['line' => $tee, 'quantity' => 2]], null, null, $this->now, $this->actor, $this->now);

        $this->assertRefused(ShipmentRefused::EXCEEDS_REMAINING, fn () => $order->ship([['line' => $tee, 'quantity' => 2]], null, null, $this->now, $this->actor, $this->now));
        self::assertSame([1, 2], [$tee->reservedQuantity(), $tee->shippedQuantity()], 'A refused shipment changes nothing.');
        self::assertCount(1, $order->shipments());
    }

    public function testNothingShipsBeforeConfirmationOrWhileOnHold(): void
    {
        $pending = $this->order([1]);
        $this->assertRefused(ShipmentRefused::NOT_SHIPPABLE, fn () => $pending->ship([['line' => $pending->lines()[0], 'quantity' => 1]], null, null, $this->now, $this->actor, $this->now));

        $held = $this->confirmedOrder([1]);
        $held->apply(Transition::Hold, $this->actor, $this->now);
        $this->assertRefused(ShipmentRefused::NOT_SHIPPABLE, fn () => $held->ship([['line' => $held->lines()[0], 'quantity' => 1]], null, null, $this->now, $this->actor, $this->now));
    }

    public function testALineWithNothingReservedCannotShip(): void
    {
        // Confirmed without a reservation: an order from before reservations existed.
        $order = $this->order([2]);
        $order->apply(Transition::Confirm, $this->actor, $this->now);

        $this->assertRefused(ShipmentRefused::NOT_RESERVED, fn () => $order->ship([['line' => $order->lines()[0], 'quantity' => 1]], null, null, $this->now, $this->actor, $this->now));
    }

    public function testShipIsNotATransitionAPersonCanAskFor(): void
    {
        $order = $this->confirmedOrder([1]);

        self::assertNotContains(Transition::Ship, $order->availableTransitions());
    }

    public function testPartOfAnOrderThatHasShippedCannotBeCancelled(): void
    {
        $order = $this->confirmedOrder([2]);
        $order->ship([['line' => $order->lines()[0], 'quantity' => 1]], null, null, $this->now, $this->actor, $this->now);

        self::assertNotContains(Transition::Cancel, $order->availableTransitions());
        $this->expectException(TransitionNotAllowed::class);
        $order->apply(Transition::Cancel, $this->actor, $this->now);
    }

    /** @param list<int> $quantities one line each: TEE, SOCKS, … */
    private function order(array $quantities): Order
    {
        $skus = ['TEE', 'SOCKS', 'CAP'];

        return Order::place(
            '10001',
            new Channel('manual', 'Manual', 'manual', 'SEK', $this->now),
            'SEK',
            new Location('WH1', 'Main', new Address(), $this->now),
            new OrderCustomer(null, 'Anna', null, ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'], null),
            array_map(fn (int $quantity, int $index): NewOrderLine => new NewOrderLine(new Product($skus[$index], $skus[$index], null, null, $this->now), $skus[$index], $quantity, 100), $quantities, array_keys($quantities)),
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
    private function confirmedOrder(array $quantities): Order
    {
        $order = $this->order($quantities);
        $order->apply(Transition::Confirm, $this->actor, $this->now);
        array_map(static fn (OrderLine $line) => $line->markReserved(), $order->lines());

        return $order;
    }

    private function assertRefused(string $reason, callable $ship): void
    {
        try {
            $ship();
            self::fail(\sprintf('Expected the shipment to be refused (%s).', $reason));
        } catch (ShipmentRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
