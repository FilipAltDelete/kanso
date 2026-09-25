<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Order;

use Kanso\Core\Internal\Application\Exception\ApplicationException;
use Kanso\Core\Internal\Application\Exception\NotFound;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Order\Transition;

/**
 * One transition — confirm, hold, cancel… — applied to many orders (ADR-0015).
 *
 * Each order goes through OrderService::transition() on its own, in its own
 * transaction, exactly as if an operator had clicked it on the order page:
 * the same state machine, the same stock reservation, the same events. One
 * order that cannot move (not enough stock, not allowed from its status,
 * changed by someone else) is reported and does not stop the others.
 */
final class BulkTransitions
{
    public const int MAX_ORDERS = 500;

    public function __construct(private readonly OrderService $orders)
    {
    }

    /**
     * @param mixed $orders `[{id, version?}]`; a version is the one the caller saw,
     *                      and without one the order's current version is used
     */
    public function apply(mixed $orders, mixed $transition, Actor $actor): BulkTransitionResult
    {
        $check = new OrderInput();
        $name = $check->text($transition, 'transition', 32);
        if (null !== $name && null === Transition::tryFrom($name)) {
            $check->violate('transition', \sprintf('Unknown transition "%s"; one of: %s.', $name, implode(', ', Transition::values())), 'unknown_transition');
        }
        if (!\is_array($orders) || !array_is_list($orders) || [] === $orders) {
            $check->violate('orders', 'Send at least one order.', 'required');
        } elseif (\count($orders) > self::MAX_ORDERS) {
            $check->violate('orders', \sprintf('At most %d orders at once.', self::MAX_ORDERS), 'too_many');
        } else {
            foreach ($orders as $index => $order) {
                if (!\is_array($order) || !\is_string($order['id'] ?? null)) {
                    $check->violate(\sprintf('orders[%d].id', $index), 'Each order needs its id.', 'required');
                } elseif (null !== ($order['version'] ?? null) && !\is_int($order['version'])) {
                    $check->violate(\sprintf('orders[%d].version', $index), 'A version is a whole number.', 'type');
                }
            }
        }
        $check->throwIfInvalid();
        \assert(\is_array($orders) && null !== $name);

        // Each order once, with the first version given for it.
        $versions = [];
        foreach ($orders as $order) {
            $versions[$order['id']] ??= $order['version'] ?? null;
        }

        $moved = [];
        $failed = [];
        foreach ($versions as $id => $version) {
            $id = (string) $id;
            try {
                $version ??= $this->orders->get($id)->version();
                $moved[] = $this->orders->transition($id, $name, $version, $actor);
            } catch (NotFound $e) {
                $failed[] = ['id' => $id, 'number' => null, 'code' => 'not_found', 'message' => $e->getMessage()];
            } catch (ApplicationException $e) {
                // The request itself was checked above, so whatever is left is
                // about this order: stale, not allowed, short stock…
                $violation = $e->violations()[0] ?? null;
                $failed[] = ['id' => $id, 'number' => $this->number($id), 'code' => $violation['code'] ?? 'failed', 'message' => $e->getMessage()];
            }
        }

        return new BulkTransitionResult($name, $moved, $failed);
    }

    private function number(string $id): ?string
    {
        try {
            return $this->orders->get($id)->number();
        } catch (ApplicationException) {
            return null;
        }
    }
}
