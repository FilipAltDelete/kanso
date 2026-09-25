<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Order;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Order\NewOrderLine;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderChangeRefused;
use Kanso\Core\Internal\Domain\Order\OrderEdit;
use Kanso\Core\Internal\Domain\Order\OrderLine;
use Psr\Clock\ClockInterface;

/**
 * Changing what an order is after it was placed (ADR-0011): editing it before
 * fulfillment, and cancelling some of its units. Both take the version the
 * caller saw, and each is one transaction: the order, its event, and the
 * stock that follows (OrderStock) commit together or not at all.
 */
final class OrderEditService
{
    /** The longest cancellation reason kept in the event. */
    public const int MAX_REASON = 255;

    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderStock $stock,
        private readonly TransactionInterface $transaction,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Edits an order. Every field is optional and a field left out is left
     * as it is: `lines` is a list of changes (`{lineId, quantity}` sets a
     * line's ordered quantity, 0 removes it; `{sku, quantity, unitPrice,
     * name?}` adds a line), `customer` takes `name` and `email`, and the
     * addresses are replaced whole (`billingAddress: null` clears it).
     *
     * @param array<string, mixed> $input only the fields the request carried
     */
    public function edit(string $id, array $input, Actor $actor): Order
    {
        $check = new OrderInput();
        $expected = $check->integer($input['version'] ?? null, 'version', 1, \PHP_INT_MAX);

        $customerName = null;
        $changeEmail = false;
        $customerEmail = null;
        if (\array_key_exists('customer', $input)) {
            $customer = $input['customer'];
            if (!\is_array($customer)) {
                $check->violate('customer', 'This value must be an object.', 'type');
            } else {
                if (\array_key_exists('name', $customer)) {
                    $customerName = $check->text($customer['name'], 'customer.name', 255);
                }
                if (\array_key_exists('email', $customer)) {
                    $changeEmail = true;
                    $customerEmail = $check->email($customer['email'], 'customer.email');
                }
            }
        }
        $shipping = \array_key_exists('shippingAddress', $input) ? $check->address($input['shippingAddress'], 'shippingAddress', true) : null;
        $changeBilling = \array_key_exists('billingAddress', $input);
        $billing = $changeBilling ? $check->address($input['billingAddress'], 'billingAddress', false) : null;

        $order = $this->orders->get($id);
        [$quantities, $removed, $added, $paths] = $this->lineChanges($input['lines'] ?? null, \array_key_exists('lines', $input), $order, $check);
        $check->throwIfInvalid();
        \assert(null !== $expected);

        if ($order->version() !== $expected) {
            throw $this->stale($order);
        }

        $edit = new OrderEdit($quantities, $removed, array_column($added, 'line'), $customerName, $changeEmail, $customerEmail, $shipping, $changeBilling, $billing);
        $now = $this->clock->now();
        try {
            $this->transaction->run(function () use ($order, $edit, $quantities, $removed, $added, $paths, $actor, $now): void {
                $before = $order->lines();
                try {
                    $event = $order->edit($edit, $actor, $now);
                } catch (OrderChangeRefused $e) {
                    throw $this->refused($e, $paths, $order);
                } catch (\OverflowException) {
                    throw new ValidationFailed([['path' => 'lines', 'message' => 'The order total is too large.', 'code' => 'out_of_range']]);
                }
                if (null === $event) {
                    return;
                }

                // The lines the edit added, in the order it added them, get their request paths.
                $new = array_values(array_filter($order->lines(), static fn (OrderLine $line): bool => !\in_array($line, $before, true)));
                foreach ($new as $index => $line) {
                    $paths[spl_object_id($line)] = $added[$index]['path'];
                }

                $touched = [...array_column($quantities, 'line'), ...$removed, ...$new];
                $this->stock->follow($order, $touched, $paths, $actor, $now);
            });
        } catch (ConcurrentModification) {
            throw $this->stale($order);
        }

        return $order;
    }

    /**
     * Cancels some units of some lines: `{version, lines: [{lineId, quantity}], reason?}`.
     * Their reservation is released in the same transaction; when nothing is
     * left to ship, the order finishes (shipped or cancelled) through the
     * state machine.
     *
     * @param array<string, mixed> $input
     */
    public function cancelUnits(string $id, array $input, Actor $actor): Order
    {
        $check = new OrderInput();
        $expected = $check->integer($input['version'] ?? null, 'version', 1, \PHP_INT_MAX);
        $reason = $check->text($input['reason'] ?? null, 'reason', self::MAX_REASON, false);

        $order = $this->orders->get($id);
        [$lines, $paths] = $this->cancelLines($input['lines'] ?? null, $order, $check);
        $check->throwIfInvalid();
        \assert(null !== $expected && [] !== $lines);

        if ($order->version() !== $expected) {
            throw $this->stale($order);
        }

        $now = $this->clock->now();
        try {
            $this->transaction->run(function () use ($order, $lines, $paths, $reason, $actor, $now): void {
                try {
                    $released = $order->cancelUnits($lines, $reason, $actor, $now);
                } catch (OrderChangeRefused $e) {
                    throw $this->refused($e, $paths, $order);
                }
                $this->stock->releaseCancelled($order, $released, $actor, $now);
            });
        } catch (ConcurrentModification) {
            throw $this->stale($order);
        }

        return $order;
    }

    /**
     * The edit's `lines`: changes to lines of this order (each at most once)
     * and new lines. Returns the new quantities, the lines removed, the lines
     * added, and the request path of each existing line by spl_object_id().
     *
     * @return array{list<array{line: OrderLine, quantity: int}>, list<OrderLine>, list<array{line: NewOrderLine, path: string}>, array<int, string>}
     */
    private function lineChanges(mixed $value, bool $sent, Order $order, OrderInput $check): array
    {
        if (!$sent) {
            return [[], [], [], []];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            $check->violate('lines', 'This value must be a list of line changes.', 'type');

            return [[], [], [], []];
        }

        $byId = [];
        foreach ($order->lines() as $line) {
            $byId[strtolower((string) $line->id())] = $line;
        }

        $quantities = [];
        $removed = [];
        $added = [];
        $paths = [];
        foreach ($value as $index => $entry) {
            $path = \sprintf('lines[%d]', $index);
            if (!\is_array($entry)) {
                $check->violate($path, 'A line must be an object.', 'type');
                continue;
            }

            if (!\array_key_exists('lineId', $entry)) {
                $new = $this->orders->newLine($entry, $path, $check);
                if (null !== $new) {
                    $added[] = ['line' => $new, 'path' => $path];
                }
                continue;
            }

            $lineId = $check->uuid($entry['lineId'], $path.'.lineId');
            $quantity = $check->integer($entry['quantity'] ?? null, $path.'.quantity', 0, OrderService::MAX_QUANTITY);
            foreach (['sku', 'name', 'unitPrice'] as $field) {
                if (\array_key_exists($field, $entry)) {
                    $check->violate($path.'.'.$field, 'A line keeps its SKU, name and price; remove it and add a new line instead.', 'not_editable');
                }
            }
            if (null === $lineId) {
                continue;
            }
            $line = $byId[strtolower($lineId)] ?? null;
            if (null === $line) {
                $check->violate($path.'.lineId', \sprintf('Order %s has no line "%s".', $order->number(), $lineId), 'unknown_line');
                continue;
            }
            if (isset($paths[spl_object_id($line)])) {
                $check->violate($path.'.lineId', 'Each line once per edit.', 'duplicate_line');
                continue;
            }
            $paths[spl_object_id($line)] = $path;
            if (null === $quantity) {
                continue;
            }

            if (0 === $quantity) {
                if ($line->shippedQuantity() > 0) {
                    $check->violate($path.'.quantity', \sprintf('Line %d (%s) has shipped units and cannot be removed.', $line->position(), $line->skuCode()), OrderChangeRefused::BELOW_DONE);
                    continue;
                }
                $removed[] = $line;
            } elseif ($quantity < $line->shippedQuantity() + $line->cancelledQuantity()) {
                $check->violate($path.'.quantity', \sprintf('Line %d (%s) has %d shipped or cancelled; its quantity cannot go below that.', $line->position(), $line->skuCode(), $line->shippedQuantity() + $line->cancelledQuantity()), OrderChangeRefused::BELOW_DONE);
            } else {
                $quantities[] = ['line' => $line, 'quantity' => $quantity];
            }
        }

        if (\count($order->lines()) - \count($removed) + \count($added) > OrderService::MAX_LINES) {
            $check->violate('lines', \sprintf('An order can have at most %d lines.', OrderService::MAX_LINES), 'too_many');
        }

        return [$quantities, $removed, $added, $paths];
    }

    /**
     * `[{lineId, quantity}]`, each line of this order at most once, and no
     * more than it has left to ship.
     *
     * @return array{list<array{line: OrderLine, quantity: int}>, array<int, string>}
     */
    private function cancelLines(mixed $value, Order $order, OrderInput $check): array
    {
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            $check->violate('lines', 'Name at least one line to cancel units of.', 'required');

            return [[], []];
        }

        $byId = [];
        foreach ($order->lines() as $line) {
            $byId[strtolower((string) $line->id())] = $line;
        }

        $lines = [];
        $paths = [];
        foreach ($value as $index => $entry) {
            $path = \sprintf('lines[%d]', $index);
            if (!\is_array($entry)) {
                $check->violate($path, 'A line must be an object.', 'type');
                continue;
            }
            $lineId = $check->uuid($entry['lineId'] ?? null, $path.'.lineId');
            $quantity = $check->integer($entry['quantity'] ?? null, $path.'.quantity', 1, OrderService::MAX_QUANTITY);
            if (null === $lineId) {
                if (!isset($entry['lineId'])) {
                    $check->violate($path.'.lineId', 'This value is required.', 'required');
                }
                continue;
            }
            $line = $byId[strtolower($lineId)] ?? null;
            if (null === $line) {
                $check->violate($path.'.lineId', \sprintf('Order %s has no line "%s".', $order->number(), $lineId), 'unknown_line');
                continue;
            }
            if (isset($paths[spl_object_id($line)])) {
                $check->violate($path.'.lineId', 'Each line once per cancellation.', 'duplicate_line');
                continue;
            }
            $paths[spl_object_id($line)] = $path;
            if (null !== $quantity && $quantity > $line->remainingQuantity()) {
                $check->violate($path.'.quantity', \sprintf('Line %d (%s) has %d left to cancel; shipped units cannot be cancelled.', $line->position(), $line->skuCode(), $line->remainingQuantity()), OrderChangeRefused::EXCEEDS_REMAINING);
                continue;
            }
            if (null !== $quantity) {
                $lines[] = ['line' => $line, 'quantity' => $quantity];
            }
        }

        return [$lines, $paths];
    }

    /**
     * A refusal as the API reports it: the order's status is a conflict (409),
     * a line that cannot take the change is a violation at its path (422).
     *
     * @param array<int, string> $paths
     */
    private function refused(OrderChangeRefused $e, array $paths, Order $order): Conflict|ValidationFailed
    {
        if (\in_array($e->reason, [OrderChangeRefused::NOT_EDITABLE, OrderChangeRefused::NOT_CANCELLABLE, OrderChangeRefused::RELEASE_FIRST], true)) {
            return new Conflict($e->getMessage(), [['path' => 'status', 'message' => $e->getMessage(), 'code' => $e->reason]]);
        }

        $path = 'lines';
        foreach ($order->lines() as $line) {
            if ($line->position() === $e->linePosition && isset($paths[spl_object_id($line)])) {
                $path = $paths[spl_object_id($line)].'.quantity';
            }
        }

        return new ValidationFailed([['path' => $path, 'message' => $e->getMessage(), 'code' => $e->reason]]);
    }

    private function stale(Order $order): Conflict
    {
        return new Conflict(
            \sprintf('Order %s was changed by someone else. Reload it and try again.', $order->number()),
            [['path' => 'version', 'message' => 'The order has changed since this version.', 'code' => 'stale_version']],
        );
    }
}
