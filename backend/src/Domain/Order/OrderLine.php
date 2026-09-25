<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Doctrine\ORM\Mapping as ORM;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One SKU on an order. The SKU code, name and price are copied: the order
 * keeps what was sold even when the catalogue changes. The line is also linked
 * to its product, which is what stock is reserved against.
 *
 * `reservedQuantity` is how much of this line is held in stock at the order's
 * location. While the order holds stock, every unit is reserved, shipped or
 * cancelled: reserved = quantity − shipped − cancelled; otherwise it is 0.
 * OrderStock changes it (and the domain methods that move units out of it:
 * a shipment, a partial cancel), in the transaction that changes the
 * inventory level.
 *
 * `quantity` is what was ordered, as last edited; cancelled units stay
 * counted in it, so the line explains itself: `lineTotal` is the unit price
 * times the units not cancelled (ADR-0011).
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_line')]
class OrderLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column]
    private int $position;

    /** Null only on lines placed before order lines were linked to products. */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: true)]
    private ?Product $product;

    #[ORM\Column(name: 'sku_code', length: 64)]
    private string $skuCode;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private int $quantity;

    /** Minor units, in the order's currency. */
    #[ORM\Column(name: 'unit_price', type: 'bigint')]
    private int $unitPrice;

    #[ORM\Column(name: 'line_total', type: 'bigint')]
    private int $lineTotal;

    #[ORM\Column(name: 'reserved_quantity', options: ['default' => 0])]
    private int $reservedQuantity = 0;

    /** Units that have left in shipments. reserved + shipped = quantity while the order holds stock. */
    #[ORM\Column(name: 'shipped_quantity', options: ['default' => 0])]
    private int $shippedQuantity = 0;

    /** Units cancelled by a partial cancel; never shipped. shipped + cancelled ≤ quantity. */
    #[ORM\Column(name: 'cancelled_quantity', options: ['default' => 0])]
    private int $cancelledQuantity = 0;

    public function __construct(Order $order, int $position, NewOrderLine $line)
    {
        if ($line->quantity < 1) {
            throw new \InvalidArgumentException('A line needs a quantity of at least 1.');
        }
        if ($line->unitPrice < 0) {
            throw new \InvalidArgumentException('A unit price cannot be negative.');
        }

        $this->id = Uuid::v7();
        $this->order = $order;
        $this->position = $position;
        $this->product = $line->product;
        $this->skuCode = $line->product->sku();
        $this->name = $line->name;
        $this->quantity = $line->quantity;
        $this->unitPrice = $line->unitPrice;
        $this->recalculate();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function product(): ?Product
    {
        return $this->product;
    }

    public function reservedQuantity(): int
    {
        return $this->reservedQuantity;
    }

    /** Every unit still to ship is now held in stock. */
    public function markReserved(): void
    {
        if (0 !== $this->reservedQuantity) {
            throw new \LogicException(\sprintf('Line %d is already reserved.', $this->position));
        }
        $this->reservedQuantity = $this->remainingQuantity();
    }

    /**
     * The reservation grows or shrinks by `$delta` after an edit; OrderStock
     * moves the same units on the inventory level.
     */
    public function adjustReservation(int $delta): void
    {
        $reserved = $this->reservedQuantity + $delta;
        if ($reserved < 0 || $reserved > $this->remainingQuantity()) {
            throw new \LogicException(\sprintf('Line %d cannot hold %d reserved; %d left to ship.', $this->position, $reserved, $this->remainingQuantity()));
        }
        $this->reservedQuantity = $reserved;
    }

    /** What was held is no longer: released when the order is cancelled. */
    public function clearReservation(): void
    {
        $this->reservedQuantity = 0;
    }

    public function shippedQuantity(): int
    {
        return $this->shippedQuantity;
    }

    public function cancelledQuantity(): int
    {
        return $this->cancelledQuantity;
    }

    /** Units still to ship: neither shipped nor cancelled. */
    public function remainingQuantity(): int
    {
        return $this->quantity - $this->shippedQuantity - $this->cancelledQuantity;
    }

    /**
     * An edit's new ordered quantity. It cannot go below what has already
     * shipped or been cancelled, and a line has at least one unit (to drop a
     * line, the edit removes it). The reservation is OrderStock's to follow.
     */
    public function changeQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity < $this->shippedQuantity + $this->cancelledQuantity) {
            throw new \InvalidArgumentException(\sprintf('Line %d needs a quantity of at least %d.', $this->position, max(1, $this->shippedQuantity + $this->cancelledQuantity)));
        }
        $this->quantity = $quantity;
        $this->recalculate();
    }

    /**
     * Units that will not ship: they count as cancelled, stop being reserved
     * (where they were), and leave the line total.
     *
     * @return int how many of them were reserved, for OrderStock to release
     */
    public function cancel(int $units): int
    {
        if ($units < 1 || $units > $this->remainingQuantity()) {
            throw new \LogicException(\sprintf('Line %d cannot cancel %d; %d left.', $this->position, $units, $this->remainingQuantity()));
        }
        // Orders confirmed before reservations existed hold nothing to release.
        $released = min($units, $this->reservedQuantity);
        $this->reservedQuantity -= $released;
        $this->cancelledQuantity += $units;
        $this->recalculate();

        return $released;
    }

    /** @return array{position: int, sku: string, name: string, quantity: int, cancelledQuantity: int, unitPrice: int} what an event records of the line */
    public function snapshot(): array
    {
        return [
            'position' => $this->position,
            'sku' => $this->skuCode,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'cancelledQuantity' => $this->cancelledQuantity,
            'unitPrice' => $this->unitPrice,
        ];
    }

    /** The units not cancelled, at the unit price. */
    private function recalculate(): void
    {
        $this->lineTotal = Money::of($this->unitPrice, $this->order->currency())->multiply($this->quantity - $this->cancelledQuantity)->amount;
    }

    /**
     * Units left in a shipment: they stop being reserved and count as shipped,
     * so reserved + shipped stays equal to the quantity.
     */
    public function markShipped(int $units): void
    {
        if ($units < 1 || $units > $this->remainingQuantity()) {
            throw new \LogicException(\sprintf('Line %d cannot ship %d; %d left.', $this->position, $units, $this->remainingQuantity()));
        }
        if ($units > $this->reservedQuantity) {
            throw new \LogicException(\sprintf('Line %d holds %d reserved; cannot ship %d.', $this->position, $this->reservedQuantity, $units));
        }
        $this->reservedQuantity -= $units;
        $this->shippedQuantity += $units;
    }

    /** A voided shipment's units: not shipped after all, reserved again. */
    public function markUnshipped(int $units): void
    {
        if ($units < 1 || $units > $this->shippedQuantity) {
            throw new \LogicException(\sprintf('Line %d has %d shipped; cannot unship %d.', $this->position, $this->shippedQuantity, $units));
        }
        $this->shippedQuantity -= $units;
        $this->reservedQuantity += $units;
    }

    public function skuCode(): string
    {
        return $this->skuCode;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPrice(): Money
    {
        return Money::of($this->unitPrice, $this->order->currency());
    }

    public function lineTotal(): Money
    {
        return Money::of($this->lineTotal, $this->order->currency());
    }
}
