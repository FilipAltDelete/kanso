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
 * location: all of it while the order is confirmed and not yet shipped, none
 * otherwise. Only OrderStock changes it, in the transaction that changes the
 * inventory level.
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
        $this->lineTotal = Money::of($line->unitPrice, $order->currency())->multiply($line->quantity)->amount;
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

    /** The whole line is now held in stock. */
    public function markReserved(): void
    {
        if (0 !== $this->reservedQuantity) {
            throw new \LogicException(\sprintf('Line %d is already reserved.', $this->position));
        }
        $this->reservedQuantity = $this->quantity;
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

    /** Units still to ship. */
    public function remainingQuantity(): int
    {
        return $this->quantity - $this->shippedQuantity;
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
