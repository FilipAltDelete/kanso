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
 * The core object. Its status has no setter: it changes only through
 * apply(), which asks OrderStateMachine and records an OrderEvent. The event
 * is cascaded, so it is written in the same flush, and so the same
 * transaction, as the status. `version` is Doctrine's optimistic lock: a
 * flush over a change someone else saved first fails instead of overwriting.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order')]
class Order
{
    /** The statuses in which the order's stock is reserved, and in which it can ship. */
    private const array HOLDING_STOCK = [OrderStatus::Confirmed, OrderStatus::Allocated, OrderStatus::Picking, OrderStatus::Packed];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 32, unique: true)]
    private string $number;

    /**
     * The order's reference in the system it came from (a webshop's order
     * number, a CSV file's orderReference), unique per channel so the same
     * order cannot be brought in twice. Null for orders entered by hand.
     */
    #[ORM\Column(name: 'external_reference', length: 64, nullable: true)]
    private ?string $externalReference = null;

    #[ORM\ManyToOne(targetEntity: Channel::class)]
    #[ORM\JoinColumn(name: 'channel_id', nullable: false)]
    private Channel $channel;

    #[ORM\Column(length: 16, enumType: OrderStatus::class)]
    private OrderStatus $status;

    /** Where an on-hold order returns to on release. */
    #[ORM\Column(name: 'held_from', length: 16, nullable: true, enumType: OrderStatus::class)]
    private ?OrderStatus $heldFrom = null;

    #[ORM\Column(length: 3)]
    private string $currency;

    /**
     * Where the order's stock is reserved and shipped from: one location per
     * order in Phase 1. Null only on orders placed before orders had one.
     */
    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: true)]
    private ?Location $location;

    #[ORM\Column(name: 'customer_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $customerId;

    #[ORM\Column(name: 'customer_name', length: 255)]
    private string $customerName;

    #[ORM\Column(name: 'customer_email', length: 255, nullable: true)]
    private ?string $customerEmail;

    /** @var array<string, string|null> */
    #[ORM\Column(name: 'shipping_address', type: 'json')]
    private array $shippingAddress;

    /** @var array<string, string|null>|null */
    #[ORM\Column(name: 'billing_address', type: 'json', nullable: true)]
    private ?array $billingAddress;

    /** The sum of the line totals, in minor units. */
    #[ORM\Column(name: 'total_amount', type: 'bigint')]
    private int $totalAmount = 0;

    #[ORM\Column(name: 'placed_at')]
    private \DateTimeImmutable $placedAt;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'integer')]
    #[ORM\Version]
    private int $version = 1;

    /** @var Collection<int, OrderLine> */
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, Shipment> */
    #[ORM\OneToMany(targetEntity: Shipment::class, mappedBy: 'order', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $shipments;

    /** @var Collection<int, OrderEvent> */
    #[ORM\OneToMany(targetEntity: OrderEvent::class, mappedBy: 'order', cascade: ['persist'], fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $events;

    /** @param list<NewOrderLine> $lines */
    private function __construct(string $number, Channel $channel, string $currency, Location $location, OrderCustomer $customer, array $lines, \DateTimeImmutable $placedAt, \DateTimeImmutable $now)
    {
        if ([] === $lines) {
            throw new \InvalidArgumentException('An order needs at least one line.');
        }

        $this->id = Uuid::v7();
        $this->number = $number;
        $this->channel = $channel;
        $this->status = OrderStatus::Pending;
        $this->currency = Money::zero($currency)->currency;
        $this->location = $location;
        $this->customerId = null === $customer->id ? null : Uuid::fromString($customer->id);
        $this->customerName = $customer->name;
        $this->customerEmail = $customer->email;
        $this->shippingAddress = $customer->shippingAddress;
        $this->billingAddress = $customer->billingAddress;
        $this->placedAt = $placedAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->lines = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->shipments = new ArrayCollection();

        $total = Money::zero($this->currency);
        foreach ($lines as $index => $line) {
            $orderLine = new OrderLine($this, $index + 1, $line);
            $this->lines->add($orderLine);
            $total = $total->add($orderLine->lineTotal());
        }
        $this->totalAmount = $total->amount;
    }

    /** @param list<NewOrderLine> $lines */
    public static function place(
        string $number,
        Channel $channel,
        string $currency,
        Location $location,
        OrderCustomer $customer,
        array $lines,
        \DateTimeImmutable $placedAt,
        Actor $actor,
        \DateTimeImmutable $now,
        ?string $externalReference = null,
    ): self {
        $order = new self($number, $channel, $currency, $location, $customer, $lines, $placedAt, $now);
        $order->externalReference = $externalReference;
        $order->events->add(new OrderEvent($order, OrderEvent::CREATED, null, $actor, null, $order->snapshot(), $now));

        return $order;
    }

    /** The one way to change an order's status. */
    public function apply(Transition $transition, Actor $actor, \DateTimeImmutable $now): OrderEvent
    {
        $target = OrderStateMachine::target($this->status, $transition, $this->heldFrom);
        // Part of it has left the building: cancelling the rest is a partial
        // cancel, which is its own feature (ROADMAP Phase 1), not this.
        if (null === $target || (Transition::Cancel === $transition && $this->hasShipped())) {
            throw new TransitionNotAllowed($this->status, $transition);
        }

        // Stock follows the status (OrderStock), in the same transaction;
        // this method changes the status and records it, nothing else.
        $before = $this->statusState();
        $this->heldFrom = OrderStatus::OnHold === $target ? $this->status : null;
        $this->status = $target;
        $this->updatedAt = $now;

        $event = new OrderEvent($this, OrderEvent::TRANSITION, $transition, $actor, $before, $this->statusState(), $now);
        $this->events->add($event);

        return $event;
    }

    /**
     * Whether this order has stock held for it: from confirmation until it
     * ships or is cancelled, and while on hold from one of those statuses.
     */
    public function holdsStock(): bool
    {
        return \in_array($this->status, self::HOLDING_STOCK, true)
            || (OrderStatus::OnHold === $this->status && \in_array($this->heldFrom, self::HOLDING_STOCK, true));
    }

    /** For an order placed before orders had a location; set when it is first confirmed. */
    public function assignLocation(Location $location): void
    {
        if (null !== $this->location) {
            throw new \LogicException(\sprintf('Order %s already has a location.', $this->number));
        }
        $this->location = $location;
    }

    /**
     * Records a shipment of some or all of what is left to ship. The lines'
     * shipped quantities go up and their reservations down (the stock itself
     * is OrderStock's, in the same transaction); an event records it; and when
     * nothing is left to ship, the order moves to shipped through the state
     * machine.
     *
     * @param non-empty-list<array{line: OrderLine, quantity: int}> $lines lines of this order
     *
     * @throws ShipmentRefused
     */
    public function ship(array $lines, ?string $carrier, ?string $trackingNumber, \DateTimeImmutable $shippedAt, Actor $actor, \DateTimeImmutable $now): Shipment
    {
        if (!$this->canShip()) {
            throw new ShipmentRefused(\sprintf('Order %s cannot ship while it is %s.', $this->number, $this->status->value), ShipmentRefused::NOT_SHIPPABLE);
        }
        if ([] === $lines) {
            throw new ShipmentRefused('A shipment needs at least one unit.', ShipmentRefused::EMPTY);
        }

        $shipping = [];
        foreach ($lines as $entry) {
            $line = $entry['line'];
            if (!$this->lines->contains($line)) {
                throw new \InvalidArgumentException(\sprintf('Line %d is not a line of order %s.', $line->position(), $this->number));
            }
            $shipping[$line->position()] = ($shipping[$line->position()] ?? 0) + $entry['quantity'];
            if ($entry['quantity'] < 1 || $shipping[$line->position()] > $line->remainingQuantity()) {
                throw new ShipmentRefused(\sprintf('Line %d (%s) has %d left to ship.', $line->position(), $line->skuCode(), $line->remainingQuantity()), ShipmentRefused::EXCEEDS_REMAINING, $line->position());
            }
            if ($shipping[$line->position()] > $line->reservedQuantity()) {
                throw new ShipmentRefused(\sprintf('Line %d (%s) has no stock reserved to ship; it was confirmed before reservations existed.', $line->position(), $line->skuCode()), ShipmentRefused::NOT_RESERVED, $line->position());
            }
        }

        $location = $this->location ?? throw new \LogicException(\sprintf('Order %s has no location.', $this->number));
        $shipment = new Shipment($this, $location, $lines, $carrier, $trackingNumber, $shippedAt, $actor, $now);
        foreach ($lines as $entry) {
            $entry['line']->markShipped($entry['quantity']);
        }
        $this->shipments->add($shipment);
        $this->updatedAt = $now;

        $this->events->add(new OrderEvent($this, OrderEvent::SHIPMENT, null, $actor, null, [
            'shipment' => (string) $shipment->id(),
            'carrier' => $carrier,
            'trackingNumber' => $trackingNumber,
            'lines' => array_map(static fn (ShipmentLine $line): array => ['position' => $line->orderLine()->position(), 'sku' => $line->orderLine()->skuCode(), 'quantity' => $line->quantity()], $shipment->lines()),
        ], $now));

        if (0 === $this->remainingUnits()) {
            $this->apply(Transition::Ship, $actor, $now);
        }

        return $shipment;
    }

    /** Whether a shipment can be recorded now: the order holds stock, is not on hold, and has units left. */
    public function canShip(): bool
    {
        return \in_array($this->status, self::HOLDING_STOCK, true) && $this->remainingUnits() > 0;
    }

    /** Whether any of the order has left in a shipment. */
    public function hasShipped(): bool
    {
        return !$this->shipments->isEmpty();
    }

    private function remainingUnits(): int
    {
        return array_sum(array_map(static fn (OrderLine $line): int => $line->remainingQuantity(), $this->lines()));
    }

    /**
     * The transitions a person can ask for now. `ship` is not one of them:
     * an order ships through shipments, and moves to shipped by itself when
     * the last one goes.
     *
     * @return list<Transition>
     */
    public function availableTransitions(): array
    {
        return array_values(array_filter(
            OrderStateMachine::available($this->status, $this->heldFrom),
            fn (Transition $transition): bool => Transition::Ship !== $transition && !(Transition::Cancel === $transition && $this->hasShipped()),
        ));
    }

    /** @return list<Shipment> oldest first */
    public function shipments(): array
    {
        return array_values($this->shipments->toArray());
    }

    /** @return array{status: string, heldFrom: string|null} */
    private function statusState(): array
    {
        return ['status' => $this->status->value, 'heldFrom' => $this->heldFrom?->value];
    }

    /** @return array<string, mixed> what the created event records */
    private function snapshot(): array
    {
        return [
            ...$this->statusState(),
            'channel' => $this->channel->code(),
            ...(null === $this->externalReference ? [] : ['externalReference' => $this->externalReference]),
            'currency' => $this->currency,
            'total' => $this->totalAmount,
            'lines' => $this->lines->count(),
        ];
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function externalReference(): ?string
    {
        return $this->externalReference;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function heldFrom(): ?OrderStatus
    {
        return $this->heldFrom;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function location(): ?Location
    {
        return $this->location;
    }

    public function customerId(): ?Uuid
    {
        return $this->customerId;
    }

    public function customerName(): string
    {
        return $this->customerName;
    }

    public function customerEmail(): ?string
    {
        return $this->customerEmail;
    }

    /** @return array<string, string|null> */
    public function shippingAddress(): array
    {
        return $this->shippingAddress;
    }

    /** @return array<string, string|null>|null */
    public function billingAddress(): ?array
    {
        return $this->billingAddress;
    }

    public function total(): Money
    {
        return Money::of($this->totalAmount, $this->currency);
    }

    public function placedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return list<OrderLine> */
    public function lines(): array
    {
        return array_values($this->lines->toArray());
    }

    /** @return list<OrderEvent> oldest first */
    public function events(): array
    {
        return array_values($this->events->toArray());
    }
}
