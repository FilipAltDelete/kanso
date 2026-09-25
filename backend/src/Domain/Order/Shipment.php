<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A parcel that left a location: some or all of an order's lines, each with
 * how many units went. Carrier and tracking number are typed in by hand in
 * Phase 1 (carrier integrations are Phase 2), and either may be absent — a
 * local courier has no tracking number. Only Order::ship() creates one. After
 * that only two things can happen to it, both through Order and both recorded:
 * its carrier and tracking number corrected, or the whole shipment voided.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shipment')]
#[ORM\Index(name: 'idx_shipment_order', columns: ['order_id'])]
// A tracking number is what a customer calls about.
#[ORM\Index(name: 'idx_shipment_tracking', columns: ['tracking_number'])]
class Shipment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'shipments')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: false)]
    private Location $location;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $carrier;

    #[ORM\Column(name: 'tracking_number', length: 128, nullable: true)]
    private ?string $trackingNumber;

    #[ORM\Column(name: 'shipped_at')]
    private \DateTimeImmutable $shippedAt;

    #[ORM\Column(name: 'actor_id', length: 64)]
    private string $actorId;

    #[ORM\Column(name: 'actor_name', length: 255)]
    private string $actorName;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** Set when the shipment was recorded by mistake and taken back; the row stays, for the history. */
    #[ORM\Column(name: 'voided_at', nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    #[ORM\Column(name: 'voided_by_id', length: 64, nullable: true)]
    private ?string $voidedById = null;

    #[ORM\Column(name: 'voided_by_name', length: 255, nullable: true)]
    private ?string $voidedByName = null;

    #[ORM\Column(name: 'void_reason', length: 500, nullable: true)]
    private ?string $voidReason = null;

    /** @var Collection<int, ShipmentLine> */
    #[ORM\OneToMany(targetEntity: ShipmentLine::class, mappedBy: 'shipment', cascade: ['persist'])]
    private Collection $lines;

    /** @param non-empty-list<array{line: OrderLine, quantity: int}> $lines */
    public function __construct(
        Order $order,
        Location $location,
        array $lines,
        ?string $carrier,
        ?string $trackingNumber,
        \DateTimeImmutable $shippedAt,
        Actor $actor,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->location = $location;
        $this->carrier = $carrier;
        $this->trackingNumber = $trackingNumber;
        $this->shippedAt = $shippedAt;
        $this->actorId = $actor->id;
        $this->actorName = $actor->name;
        $this->createdAt = $now;
        $this->lines = new ArrayCollection();
        foreach ($lines as $line) {
            $this->lines->add(new ShipmentLine($this, $line['line'], $line['quantity']));
        }
    }

    /** @internal Order::correctShipment() */
    public function correct(?string $carrier, ?string $trackingNumber): void
    {
        $this->carrier = $carrier;
        $this->trackingNumber = $trackingNumber;
    }

    /** @internal Order::voidShipment() */
    public function void(?string $reason, Actor $actor, \DateTimeImmutable $now): void
    {
        $this->voidedAt = $now;
        $this->voidedById = $actor->id;
        $this->voidedByName = $actor->name;
        $this->voidReason = $reason;
    }

    public function isVoided(): bool
    {
        return null !== $this->voidedAt;
    }

    public function voidedAt(): ?\DateTimeImmutable
    {
        return $this->voidedAt;
    }

    public function voidedBy(): ?Actor
    {
        return null === $this->voidedById ? null : new Actor($this->voidedById, (string) $this->voidedByName);
    }

    public function voidReason(): ?string
    {
        return $this->voidReason;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function location(): Location
    {
        return $this->location;
    }

    public function carrier(): ?string
    {
        return $this->carrier;
    }

    public function trackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    public function shippedAt(): \DateTimeImmutable
    {
        return $this->shippedAt;
    }

    public function actor(): Actor
    {
        return new Actor($this->actorId, $this->actorName);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<ShipmentLine> */
    public function lines(): array
    {
        return array_values($this->lines->toArray());
    }

    public function units(): int
    {
        return array_sum(array_map(static fn (ShipmentLine $line): int => $line->quantity(), $this->lines()));
    }
}
