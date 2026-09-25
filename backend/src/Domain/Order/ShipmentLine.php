<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** How many units of one order line went in one shipment. */
#[ORM\Entity]
#[ORM\Table(name: 'shipment_line')]
class ShipmentLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Shipment::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'shipment_id', nullable: false, onDelete: 'CASCADE')]
    private Shipment $shipment;

    #[ORM\ManyToOne(targetEntity: OrderLine::class)]
    #[ORM\JoinColumn(name: 'order_line_id', nullable: false, onDelete: 'CASCADE')]
    private OrderLine $orderLine;

    #[ORM\Column]
    private int $quantity;

    public function __construct(Shipment $shipment, OrderLine $orderLine, int $quantity)
    {
        $this->id = Uuid::v7();
        $this->shipment = $shipment;
        $this->orderLine = $orderLine;
        $this->quantity = $quantity;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function orderLine(): OrderLine
    {
        return $this->orderLine;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }
}
