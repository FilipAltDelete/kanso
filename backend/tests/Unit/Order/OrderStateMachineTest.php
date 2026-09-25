<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Order;

use Kanso\Core\Internal\Domain\Order\OrderStateMachine;
use Kanso\Core\Internal\Domain\Order\OrderStatus;
use Kanso\Core\Internal\Domain\Order\Transition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every status against every transition: what is listed in ALLOWED goes
 * where it says, and everything else is refused. A new status or transition
 * fails here until it is placed in the table.
 */
final class OrderStateMachineTest extends TestCase
{
    /** from => [transition => to]; release is covered separately, since its target depends on heldFrom. */
    private const array ALLOWED = [
        'pending' => ['confirm' => 'confirmed', 'cancel' => 'cancelled', 'hold' => 'on_hold'],
        // `ship` from any stock-holding status: the last shipment applies it.
        'confirmed' => ['allocate' => 'allocated', 'ship' => 'shipped', 'cancel' => 'cancelled', 'hold' => 'on_hold'],
        'allocated' => ['start_picking' => 'picking', 'ship' => 'shipped', 'cancel' => 'cancelled', 'hold' => 'on_hold'],
        'picking' => ['pack' => 'packed', 'ship' => 'shipped', 'cancel' => 'cancelled', 'hold' => 'on_hold'],
        'packed' => ['ship' => 'shipped', 'cancel' => 'cancelled', 'hold' => 'on_hold'],
        'shipped' => ['deliver' => 'delivered'],
        'delivered' => [],
        'cancelled' => [],
        'on_hold' => ['cancel' => 'cancelled', 'release' => 'held-from'],
    ];

    /** @return iterable<string, array{OrderStatus, Transition, OrderStatus|null}> */
    public static function everyPair(): iterable
    {
        foreach (OrderStatus::cases() as $from) {
            foreach (Transition::cases() as $transition) {
                if (OrderStatus::OnHold === $from && Transition::Release === $transition) {
                    continue;
                }
                $to = self::ALLOWED[$from->value][$transition->value] ?? null;

                yield \sprintf('%s --%s-->', $from->value, $transition->value) => [$from, $transition, null === $to ? null : OrderStatus::from($to)];
            }
        }
    }

    #[DataProvider('everyPair')]
    public function testTransition(OrderStatus $from, Transition $transition, ?OrderStatus $expected): void
    {
        // Held from pending, in case the pair is from on_hold.
        self::assertSame($expected, OrderStateMachine::target($from, $transition, OrderStatus::Pending));
    }

    public function testTheTableCoversEveryStatus(): void
    {
        self::assertSame(OrderStatus::values(), array_keys(self::ALLOWED));
    }

    /** @return iterable<string, array{OrderStatus}> */
    public static function holdable(): iterable
    {
        foreach (OrderStateMachine::HOLDABLE as $status) {
            yield $status->value => [$status];
        }
    }

    #[DataProvider('holdable')]
    public function testReleaseReturnsToWhereTheOrderWasHeldFrom(OrderStatus $heldFrom): void
    {
        self::assertSame($heldFrom, OrderStateMachine::target(OrderStatus::OnHold, Transition::Release, $heldFrom));
    }

    public function testReleaseNeedsAPlaceToReturnTo(): void
    {
        self::assertNull(OrderStateMachine::target(OrderStatus::OnHold, Transition::Release, null));
        self::assertNull(OrderStateMachine::target(OrderStatus::OnHold, Transition::Release, OrderStatus::Shipped));
    }

    /** @return iterable<string, array{OrderStatus}> */
    public static function notOnHold(): iterable
    {
        foreach (OrderStatus::cases() as $status) {
            if (OrderStatus::OnHold !== $status) {
                yield $status->value => [$status];
            }
        }
    }

    #[DataProvider('notOnHold')]
    public function testOnlyAnOnHoldOrderCanBeReleased(OrderStatus $from): void
    {
        self::assertNull(OrderStateMachine::target($from, Transition::Release, OrderStatus::Pending));
    }

    public function testListsTheAvailableTransitionsInLifecycleOrder(): void
    {
        self::assertSame([Transition::Confirm, Transition::Cancel, Transition::Hold], OrderStateMachine::available(OrderStatus::Pending));
        self::assertSame([Transition::Cancel, Transition::Release], OrderStateMachine::available(OrderStatus::OnHold, OrderStatus::Packed));
        self::assertSame([], OrderStateMachine::available(OrderStatus::Delivered));
        self::assertSame([], OrderStateMachine::available(OrderStatus::Cancelled));
    }
}
