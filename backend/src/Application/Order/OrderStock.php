<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Order;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\InventoryLevel;
use Kanso\Core\Internal\Domain\Inventory\InventoryMovement;
use Kanso\Core\Internal\Domain\Inventory\InventoryStoreInterface;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Inventory\MovementType;
use Kanso\Core\Internal\Domain\Inventory\StockChange;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderLine;
use Kanso\Core\Internal\Domain\Order\Shipment;
use Kanso\Core\Internal\Domain\Order\ShipmentLine;

/**
 * An order's stock: reserved when it is confirmed, released when it is
 * cancelled (wholly or partly), moved up or down when it is edited, taken
 * off on hand shipment by shipment.
 *
 * Every method must run inside the transaction that changes the order's
 * status, so the two commit together or not at all. The levels involved are
 * locked (SELECT … FOR UPDATE) in product-id order before anything is read:
 * two orders confirmed at the same moment for the same product take turns,
 * and the second sees what the first reserved. That is what makes overselling
 * impossible rather than unlikely; the database's CHECK (on_hand ≥ reserved)
 * is the last line behind it.
 */
final class OrderStock
{
    public function __construct(private readonly InventoryStoreInterface $inventory)
    {
    }

    /**
     * All of the order or nothing: when any product is short, nothing is
     * reserved, and the conflict names every short line.
     */
    public function reserve(Order $order, Actor $actor, \DateTimeImmutable $now): void
    {
        $location = self::location($order);
        $lines = $order->lines();

        $unlinked = [];
        foreach ($lines as $index => $line) {
            if (null === $line->product()) {
                $unlinked[] = ['path' => \sprintf('lines[%d].sku', $index), 'message' => \sprintf('Line %d (%s) is not linked to a product, so it has no stock to reserve.', $line->position(), $line->skuCode()), 'code' => 'no_product'];
            }
        }
        if ([] !== $unlinked) {
            throw new ValidationFailed($unlinked);
        }

        /** @var array<string, array{product: Product, quantity: int, index: int}> $needed */
        $needed = [];
        foreach ($lines as $index => $line) {
            // Units cancelled while the order was pending are not reserved.
            if (0 === $line->remainingQuantity()) {
                continue;
            }
            $product = self::product($line);
            $id = $product->id()->toRfc4122();
            $needed[$id] ??= ['product' => $product, 'quantity' => 0, 'index' => $index];
            $needed[$id]['quantity'] += $line->remainingQuantity();
        }

        $levels = $this->lock(array_column($needed, 'product'), $location);

        $short = [];
        foreach ($needed as $id => $need) {
            $available = $levels[$id]?->available() ?? 0;
            if ($available < $need['quantity']) {
                $short[] = [
                    'path' => \sprintf('lines[%d].quantity', $need['index']),
                    'message' => \sprintf('%s: %d available at %s, %d needed.', $need['product']->sku(), $available, $location->code(), $need['quantity']),
                    'code' => 'insufficient_stock',
                ];
            }
        }
        if ([] !== $short) {
            throw new Conflict(\sprintf('Not enough stock at %s to confirm order %s.', $location->code(), $order->number()), $short);
        }

        foreach ($lines as $line) {
            if (0 === $line->remainingQuantity()) {
                continue;
            }
            $level = $levels[self::product($line)->id()->toRfc4122()];
            \assert(null !== $level);
            $change = $level->reserve($line->remainingQuantity(), $now);
            $line->markReserved();
            $this->record(MovementType::Reservation, $level, $change, $order, $actor, $now);
        }
    }

    /** Gives back what the order held. */
    public function release(Order $order, Actor $actor, \DateTimeImmutable $now): void
    {
        $this->settle($order, MovementType::Release, $actor, $now);
    }

    /**
     * After an edit: each touched line's reservation moves to what it should
     * now be — every unit left to ship while the order holds stock, nothing
     * for a line that was removed or an order that holds nothing. Increases
     * are all or nothing, like confirm: when a product is short, nothing
     * moves, and the conflict names each line that needs more.
     *
     * @param list<OrderLine>    $touched lines the edit changed, added or removed
     * @param array<int, string> $paths   request path of each touched line, by spl_object_id(), for the violations
     */
    public function follow(Order $order, array $touched, array $paths, Actor $actor, \DateTimeImmutable $now): void
    {
        $current = $order->lines();
        $holds = $order->holdsStock();

        /** @var list<array{line: OrderLine, delta: int}> $moves */
        $moves = [];
        $unlinked = [];
        foreach ($touched as $line) {
            $target = $holds && \in_array($line, $current, true) ? $line->remainingQuantity() : 0;
            $delta = $target - $line->reservedQuantity();
            if (0 === $delta) {
                continue;
            }
            if (null === $line->product()) {
                $unlinked[] = ['path' => ($paths[spl_object_id($line)] ?? 'lines').'.lineId', 'message' => \sprintf('Line %d (%s) is not linked to a product, so it has no stock to reserve.', $line->position(), $line->skuCode()), 'code' => 'no_product'];
                continue;
            }
            $moves[] = ['line' => $line, 'delta' => $delta];
        }
        if ([] !== $unlinked) {
            throw new ValidationFailed($unlinked);
        }
        if ([] === $moves) {
            return;
        }

        $location = self::location($order);
        $levels = $this->lock(array_map(static fn (array $move): Product => self::product($move['line']), $moves), $location);

        // Per product, what the edit takes from available, net of what it gives back.
        $net = [];
        foreach ($moves as $move) {
            $id = self::product($move['line'])->id()->toRfc4122();
            $net[$id] = ($net[$id] ?? 0) + $move['delta'];
        }
        $short = [];
        foreach ($moves as $move) {
            $product = self::product($move['line']);
            $id = $product->id()->toRfc4122();
            $available = $levels[$id]?->available() ?? 0;
            if ($move['delta'] > 0 && $net[$id] > $available) {
                $short[] = [
                    'path' => ($paths[spl_object_id($move['line'])] ?? 'lines').'.quantity',
                    'message' => \sprintf('%s: %d available at %s, %d more needed.', $product->sku(), $available, $location->code(), $net[$id]),
                    'code' => 'insufficient_stock',
                ];
            }
        }
        if ([] !== $short) {
            throw new Conflict(\sprintf('Not enough stock at %s for the edit of order %s.', $location->code(), $order->number()), $short);
        }

        // Releases first, so a product that moves between lines never looks short.
        usort($moves, static fn (array $a, array $b): int => $a['delta'] <=> $b['delta']);
        foreach ($moves as $move) {
            $line = $move['line'];
            $level = $levels[self::product($line)->id()->toRfc4122()]
                ?? throw new \LogicException(\sprintf('Order %s holds stock of %s at %s, but there is no inventory level.', $order->number(), $line->skuCode(), $location->code()));
            if ($move['delta'] > 0) {
                $change = $level->reserve($move['delta'], $now);
                $type = MovementType::Reservation;
            } else {
                $change = $level->release(-$move['delta'], $now);
                $type = MovementType::Release;
            }
            $line->adjustReservation($move['delta']);
            $this->record($type, $level, $change, $order, $actor, $now);
        }
    }

    /**
     * Gives back what a partial cancel took off the lines' reservations
     * (Order::cancelUnits() has already lowered them).
     *
     * @param list<array{line: OrderLine, released: int}> $released
     */
    public function releaseCancelled(Order $order, array $released, Actor $actor, \DateTimeImmutable $now): void
    {
        $released = array_values(array_filter($released, static fn (array $entry): bool => $entry['released'] > 0));
        if ([] === $released) {
            return;
        }

        $location = self::location($order);
        $levels = $this->lock(array_map(static fn (array $entry): Product => self::product($entry['line']), $released), $location);
        foreach ($released as $entry) {
            $level = $levels[self::product($entry['line'])->id()->toRfc4122()]
                ?? throw new \LogicException(\sprintf('Order %s holds stock of %s at %s, but there is no inventory level.', $order->number(), $entry['line']->skuCode(), $location->code()));
            $change = $level->release($entry['released'], $now);
            $this->record(MovementType::Release, $level, $change, $order, $actor, $now);
        }
    }

    /**
     * What a shipment took leaves the building: on hand and reserved both go
     * down by each line's quantity, so available does not move. The order's
     * lines were already updated by Order::ship().
     */
    public function ship(Order $order, Shipment $shipment, Actor $actor, \DateTimeImmutable $now): void
    {
        $lines = $shipment->lines();
        $levels = $this->lock(array_map(static fn (ShipmentLine $line): Product => self::product($line->orderLine()), $lines), $shipment->location());

        foreach ($lines as $line) {
            $level = $levels[self::product($line->orderLine())->id()->toRfc4122()]
                ?? throw new \LogicException(\sprintf('Order %s ships %s from %s, but there is no inventory level.', $order->number(), $line->orderLine()->skuCode(), $shipment->location()->code()));
            $change = $level->consume($line->quantity(), $now);
            $this->record(MovementType::Shipment, $level, $change, $order, $actor, $now);
        }
    }

    /**
     * A voided shipment's units are back on hand and reserved for the order
     * again, at the location they left from. Order::voidShipment() has already
     * put them back on the lines.
     */
    public function unship(Order $order, Shipment $shipment, Actor $actor, \DateTimeImmutable $now): void
    {
        $lines = $shipment->lines();
        $levels = $this->lock(array_map(static fn (ShipmentLine $line): Product => self::product($line->orderLine()), $lines), $shipment->location());

        foreach ($lines as $line) {
            $level = $levels[self::product($line->orderLine())->id()->toRfc4122()]
                ?? throw new \LogicException(\sprintf('Order %s voids %s at %s, but there is no inventory level.', $order->number(), $line->orderLine()->skuCode(), $shipment->location()->code()));
            $change = $level->unconsume($line->quantity(), $now);
            $this->record(MovementType::ShipmentVoided, $level, $change, $order, $actor, $now);
        }
    }

    private function settle(Order $order, MovementType $type, Actor $actor, \DateTimeImmutable $now): void
    {
        // Orders confirmed before reservations existed hold nothing.
        $held = array_values(array_filter($order->lines(), static fn (OrderLine $line): bool => $line->reservedQuantity() > 0));
        if ([] === $held) {
            return;
        }

        $location = self::location($order);
        $levels = $this->lock(array_map(self::product(...), $held), $location);

        foreach ($held as $line) {
            $level = $levels[self::product($line)->id()->toRfc4122()]
                ?? throw new \LogicException(\sprintf('Order %s holds stock of %s at %s, but there is no inventory level.', $order->number(), $line->skuCode(), $location->code()));
            $change = $level->release($line->reservedQuantity(), $now);
            $line->clearReservation();
            $this->record($type, $level, $change, $order, $actor, $now);
        }
    }

    /**
     * Locks each product's level at the location, in product-id order.
     *
     * @param list<Product> $products
     *
     * @return array<string, InventoryLevel|null> by product id; null where there is no level
     */
    private function lock(array $products, Location $location): array
    {
        $byId = [];
        foreach ($products as $product) {
            $byId[$product->id()->toRfc4122()] = $product;
        }
        // One fixed order for every transaction, so two cannot wait on each other.
        ksort($byId, \SORT_STRING);

        $levels = [];
        foreach ($byId as $id => $product) {
            $levels[$id] = $this->inventory->lockLevel($product, $location);
        }

        return $levels;
    }

    private function record(MovementType $type, InventoryLevel $level, StockChange $change, Order $order, Actor $actor, \DateTimeImmutable $now): void
    {
        $this->inventory->addMovement(InventoryMovement::forOrder($type, $level, $change, $order->id(), $order->number(), $actor, $now));
    }

    private static function location(Order $order): Location
    {
        return $order->location() ?? throw new \LogicException(\sprintf('Order %s has no location.', $order->number()));
    }

    private static function product(OrderLine $line): Product
    {
        return $line->product() ?? throw new \LogicException(\sprintf('Line %d has no product.', $line->position()));
    }
}
