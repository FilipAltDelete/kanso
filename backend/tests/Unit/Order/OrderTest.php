<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Order;

use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Order\Channel;
use Kanso\Core\Internal\Domain\Order\NewOrderLine;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderCustomer;
use Kanso\Core\Internal\Domain\Order\OrderEvent;
use Kanso\Core\Internal\Domain\Order\OrderStatus;
use Kanso\Core\Internal\Domain\Order\Transition;
use Kanso\Core\Internal\Domain\Order\TransitionNotAllowed;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private \DateTimeImmutable $now;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-26 08:00:00', new \DateTimeZone('UTC'));
        $this->actor = new Actor('user-1', 'Ops');
    }

    /** @param list<NewOrderLine>|null $lines */
    private function order(?array $lines = null, string $currency = 'SEK'): Order
    {
        return Order::place(
            '10001',
            new Channel('manual', 'Manual', 'manual', 'SEK', $this->now),
            $currency,
            new OrderCustomer(null, 'Anna Andersson', 'anna@example.com', ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'], null),
            $lines ?? [new NewOrderLine('TSHIRT-M', 'T-shirt, M', 3, 19_950), new NewOrderLine('SOCKS', 'Socks', 2, 4_900)],
            $this->now,
            $this->actor,
            $this->now,
        );
    }

    public function testCopiesTheLinesAndTotalsThemInMinorUnits(): void
    {
        $order = $this->order();

        self::assertSame(OrderStatus::Pending, $order->status());
        self::assertSame(3 * 19_950 + 2 * 4_900, $order->total()->amount);
        self::assertSame('SEK', $order->total()->currency);

        [$first, $second] = $order->lines();
        self::assertSame([1, 'TSHIRT-M', 'T-shirt, M', 3, 19_950, 59_850], [$first->position(), $first->skuCode(), $first->name(), $first->quantity(), $first->unitPrice()->amount, $first->lineTotal()->amount]);
        self::assertSame(2, $second->position());
    }

    public function testAFreeLineIsAllowedButANegativePriceIsNot(): void
    {
        self::assertSame(0, $this->order([new NewOrderLine('GIFT', 'Gift wrap', 1, 0)])->total()->amount);

        $this->expectException(\InvalidArgumentException::class);
        $this->order([new NewOrderLine('X', 'X', 1, -1)]);
    }

    public function testATotalPastTheIntegerRangeIsRefused(): void
    {
        $this->expectException(\OverflowException::class);

        $this->order([new NewOrderLine('A', 'A', 2, intdiv(\PHP_INT_MAX, 2) + 1)]);
    }

    public function testAnOrderNeedsLines(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->order([]);
    }

    public function testPlacingRecordsACreatedEvent(): void
    {
        [$event] = $this->order()->events();

        self::assertSame(OrderEvent::CREATED, $event->type());
        self::assertNull($event->before());
        self::assertSame(['status' => 'pending', 'heldFrom' => null, 'channel' => 'manual', 'currency' => 'SEK', 'total' => 69_650, 'lines' => 2], $event->after());
        self::assertSame('Ops', $event->actor()->name);
    }

    public function testATransitionChangesTheStatusAndRecordsWhoWhatWhenBeforeAndAfter(): void
    {
        $order = $this->order();
        $later = $this->now->modify('+5 minutes');

        $event = $order->apply(Transition::Confirm, new Actor('api-key:1', 'ERP'), $later);

        self::assertSame(OrderStatus::Confirmed, $order->status());
        self::assertSame($later, $order->updatedAt());
        self::assertCount(2, $order->events());
        self::assertSame($event, $order->events()[1]);
        self::assertSame(OrderEvent::TRANSITION, $event->type());
        self::assertSame('confirm', $event->transition());
        self::assertSame(['status' => 'pending', 'heldFrom' => null], $event->before());
        self::assertSame(['status' => 'confirmed', 'heldFrom' => null], $event->after());
        self::assertSame('api-key:1', $event->actor()->id);
        self::assertSame($later, $event->occurredAt());
    }

    public function testARefusedTransitionChangesNothingAndRecordsNothing(): void
    {
        $order = $this->order();

        try {
            $order->apply(Transition::Ship, $this->actor, $this->now);
            self::fail('Shipping a pending order must be refused.');
        } catch (TransitionNotAllowed $e) {
            self::assertSame('An order that is pending cannot ship.', $e->getMessage());
        }

        self::assertSame(OrderStatus::Pending, $order->status());
        self::assertCount(1, $order->events());
    }

    public function testHoldAndReleaseReturnToWhereTheOrderWas(): void
    {
        $order = $this->order();
        $order->apply(Transition::Confirm, $this->actor, $this->now);
        $order->apply(Transition::Allocate, $this->actor, $this->now);

        $hold = $order->apply(Transition::Hold, $this->actor, $this->now);
        self::assertSame(OrderStatus::OnHold, $order->status());
        self::assertSame(OrderStatus::Allocated, $order->heldFrom());
        self::assertSame(['status' => 'on_hold', 'heldFrom' => 'allocated'], $hold->after());
        self::assertSame([Transition::Cancel, Transition::Release], $order->availableTransitions());

        $release = $order->apply(Transition::Release, $this->actor, $this->now);
        self::assertSame(OrderStatus::Allocated, $order->status());
        self::assertNull($order->heldFrom());
        self::assertSame(['status' => 'on_hold', 'heldFrom' => 'allocated'], $release->before());
    }

    public function testTheWholeLifecycle(): void
    {
        $order = $this->order();
        foreach ([Transition::Confirm, Transition::Allocate, Transition::StartPicking, Transition::Pack, Transition::Ship, Transition::Deliver] as $transition) {
            $order->apply($transition, $this->actor, $this->now);
        }

        self::assertSame(OrderStatus::Delivered, $order->status());
        self::assertSame([], $order->availableTransitions());
        self::assertCount(7, $order->events());
    }
}
