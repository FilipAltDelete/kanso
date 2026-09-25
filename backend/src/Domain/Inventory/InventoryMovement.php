<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

use Doctrine\ORM\Mapping as ORM;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\Actor;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One change to one inventory level: who, what, when, and the level before
 * and after. Written in the same transaction as the change and never updated
 * or deleted, so the current level can always be checked against its history.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'inventory_movement')]
#[ORM\Index(name: 'idx_inventory_movement_product', columns: ['product_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_inventory_movement_location', columns: ['location_id', 'occurred_at'])]
class InventoryMovement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false)]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: false)]
    private Location $location;

    #[ORM\Column(length: 32, enumType: MovementType::class)]
    private MovementType $type;

    #[ORM\Column(length: 32, nullable: true, enumType: AdjustmentReason::class)]
    private ?AdjustmentReason $reason;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note;

    #[ORM\Column(name: 'on_hand_before')]
    private int $onHandBefore;

    #[ORM\Column(name: 'on_hand_after')]
    private int $onHandAfter;

    #[ORM\Column(name: 'reserved_before')]
    private int $reservedBefore;

    #[ORM\Column(name: 'reserved_after')]
    private int $reservedAfter;

    #[ORM\Column(name: 'actor_id', length: 64)]
    private string $actorId;

    #[ORM\Column(name: 'actor_name', length: 180)]
    private string $actorName;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    private function __construct(
        InventoryLevel $level,
        MovementType $type,
        ?AdjustmentReason $reason,
        ?string $note,
        StockChange $change,
        Actor $actor,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->id = Uuid::v7();
        $this->product = $level->product();
        $this->location = $level->location();
        $this->type = $type;
        $this->reason = $reason;
        $this->note = $note;
        $this->onHandBefore = $change->onHandBefore;
        $this->onHandAfter = $change->onHandAfter;
        $this->reservedBefore = $change->reservedBefore;
        $this->reservedAfter = $change->reservedAfter;
        $this->actorId = $actor->id;
        $this->actorName = $actor->name;
        $this->occurredAt = $occurredAt;
    }

    public static function adjustment(
        InventoryLevel $level,
        StockChange $change,
        AdjustmentReason $reason,
        ?string $note,
        Actor $actor,
        \DateTimeImmutable $occurredAt,
    ): self {
        return new self($level, MovementType::Adjustment, $reason, $note, $change, $actor, $occurredAt);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function location(): Location
    {
        return $this->location;
    }

    public function type(): MovementType
    {
        return $this->type;
    }

    public function reason(): ?AdjustmentReason
    {
        return $this->reason;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function change(): StockChange
    {
        return new StockChange($this->onHandBefore, $this->onHandAfter, $this->reservedBefore, $this->reservedAfter);
    }

    public function actor(): Actor
    {
        return new Actor($this->actorId, $this->actorName);
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
