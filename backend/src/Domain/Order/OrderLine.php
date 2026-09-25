<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One SKU on an order. The SKU code, name and price are copied, not linked:
 * the order keeps what was sold even when the catalogue changes. Linking to
 * products comes with the product catalogue.
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
        $this->skuCode = $line->skuCode;
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
