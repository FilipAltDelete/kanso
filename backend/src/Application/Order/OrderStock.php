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

/**
 * An order's stock, which follows its status: reserved when it is confirmed,
 * released when it is cancelled, taken off on hand when it ships.
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
            $product = self::product($line);
            $id = $product->id()->toRfc4122();
            $needed[$id] ??= ['product' => $product, 'quantity' => 0, 'index' => $index];
            $needed[$id]['quantity'] += $line->quantity();
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
            $level = $levels[self::product($line)->id()->toRfc4122()];
            \assert(null !== $level);
            $change = $level->reserve($line->quantity(), $now);
            $line->markReserved();
            $this->record(MovementType::Reservation, $level, $change, $order, $actor, $now);
        }
    }

    /** Gives back what the order held. */
    public function release(Order $order, Actor $actor, \DateTimeImmutable $now): void
    {
        $this->settle($order, MovementType::Release, $actor, $now);
    }

    /** What the order held leaves the building: on hand and reserved both go down. */
    public function ship(Order $order, Actor $actor, \DateTimeImmutable $now): void
    {
        $this->settle($order, MovementType::Shipment, $actor, $now);
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
            $change = MovementType::Shipment === $type
                ? $level->consume($line->reservedQuantity(), $now)
                : $level->release($line->reservedQuantity(), $now);
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
