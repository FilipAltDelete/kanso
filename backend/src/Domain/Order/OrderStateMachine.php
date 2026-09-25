<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/**
 * The order lifecycle, as a table:
 *
 *   pending → confirmed → allocated → picking → packed → shipped → delivered
 *   cancel:  any status before shipped (on_hold included) → cancelled
 *   hold:    any status before shipped → on_hold, remembering where it was
 *   release: on_hold → the status it was held from
 *
 * Shipped, delivered and cancelled cannot be cancelled or held: once goods
 * have left, undoing it is a return (Phase 3), not a status change. Kanso's
 * own table rather than symfony/workflow, as in Pimsen (ADR-037 there).
 */
final class OrderStateMachine
{
    /** The forward path: transition => [from, to]. */
    private const array FORWARD = [
        'confirm' => [OrderStatus::Pending, OrderStatus::Confirmed],
        'allocate' => [OrderStatus::Confirmed, OrderStatus::Allocated],
        'start_picking' => [OrderStatus::Allocated, OrderStatus::Picking],
        'pack' => [OrderStatus::Picking, OrderStatus::Packed],
        'ship' => [OrderStatus::Packed, OrderStatus::Shipped],
        'deliver' => [OrderStatus::Shipped, OrderStatus::Delivered],
    ];

    /** Statuses an order can be put on hold from, and so returned to. */
    public const array HOLDABLE = [
        OrderStatus::Pending,
        OrderStatus::Confirmed,
        OrderStatus::Allocated,
        OrderStatus::Picking,
        OrderStatus::Packed,
    ];

    private function __construct()
    {
    }

    /**
     * Where a transition takes an order, or null when it is not allowed from
     * there. `$heldFrom` is where an on-hold order returns to on release.
     */
    public static function target(OrderStatus $from, Transition $transition, ?OrderStatus $heldFrom = null): ?OrderStatus
    {
        return match ($transition) {
            Transition::Cancel => \in_array($from, self::HOLDABLE, true) || OrderStatus::OnHold === $from ? OrderStatus::Cancelled : null,
            Transition::Hold => \in_array($from, self::HOLDABLE, true) ? OrderStatus::OnHold : null,
            Transition::Release => OrderStatus::OnHold === $from && \in_array($heldFrom, self::HOLDABLE, true) ? $heldFrom : null,
            default => self::FORWARD[$transition->value][0] === $from ? self::FORWARD[$transition->value][1] : null,
        };
    }

    /** @return list<Transition> the transitions allowed from a status, in lifecycle order */
    public static function available(OrderStatus $from, ?OrderStatus $heldFrom = null): array
    {
        return array_values(array_filter(
            Transition::cases(),
            static fn (Transition $transition): bool => null !== self::target($from, $transition, $heldFrom),
        ));
    }
}
