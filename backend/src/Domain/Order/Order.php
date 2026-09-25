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
 *
 * Notes and tags annotate the order rather than change it: they write their
 * event but leave `version` alone, so tagging a hundred orders from the list
 * never makes an operator's open order page stale. The payment status is
 * part of the order and moves `version` like any other change.
 *
 * Before fulfillment an order can be edited (lines, customer, addresses), and
 * until it ships some of its units can be cancelled (ADR-0011). Each writes
 * one event with what changed; the stock follows in OrderStock, in the same
 * transaction.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order')]
class Order
{
    public const int MAX_TAGS = 20;
    public const int MAX_NOTE_LENGTH = 2000;

    /** The statuses in which the order's stock is reserved, and in which it can ship. */
    private const array HOLDING_STOCK = [OrderStatus::Confirmed, OrderStatus::Allocated, OrderStatus::Picking, OrderStatus::Packed];

    /** The statuses an order can be edited in (or be on hold from): before picking starts. */
    private const array EDITABLE = [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Allocated];

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

    /** Where a shipped order came from, so voiding a shipment can reopen it there. */
    #[ORM\Column(name: 'shipped_from', length: 16, nullable: true, enumType: OrderStatus::class)]
    private ?OrderStatus $shippedFrom = null;

    #[ORM\Column(name: 'payment_status', length: 24, enumType: PaymentStatus::class)]
    private PaymentStatus $paymentStatus = PaymentStatus::Unpaid;

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
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
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

    /** @var Collection<int, OrderTag> */
    #[ORM\OneToMany(targetEntity: OrderTag::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private Collection $tags;

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
        $this->tags = new ArrayCollection();

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
        $target = OrderStateMachine::target($this->status, $transition, $this->heldFrom, $this->shippedFrom);
        // Part of it has left the building: cancelling the rest is a partial
        // cancel, which is its own feature (ROADMAP Phase 1), not this.
        if (null === $target || (Transition::Cancel === $transition && $this->hasShipped())) {
            throw new TransitionNotAllowed($this->status, $transition);
        }

        // Stock follows the status (OrderStock), in the same transaction;
        // this method changes the status and records it, nothing else.
        $before = $this->statusState();
        $this->heldFrom = OrderStatus::OnHold === $target ? $this->status : null;
        $this->shippedFrom = OrderStatus::Shipped === $target ? $this->status : (Transition::Reopen === $transition ? null : $this->shippedFrom);
        $this->status = $target;
        $this->updatedAt = $now;

        $event = new OrderEvent($this, OrderEvent::TRANSITION, $transition, $actor, $before, $this->statusState(), $now);
        $this->events->add($event);

        return $event;
    }

    /** A free-text note, in any status. The event is the note: who wrote it, when, and the text. */
    public function addNote(string $text, Actor $actor, \DateTimeImmutable $now): OrderEvent
    {
        $text = trim($text);
        if ('' === $text || mb_strlen($text) > self::MAX_NOTE_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A note is 1 to %d characters.', self::MAX_NOTE_LENGTH));
        }

        $event = new OrderEvent($this, OrderEvent::NOTE, null, $actor, null, ['note' => $text], $now);
        $this->events->add($event);

        return $event;
    }

    /**
     * Adds and removes tags, ignoring case: adding one the order has, or
     * removing one it does not, changes nothing. Records an event only when
     * the tags changed.
     *
     * @param list<string> $add
     * @param list<string> $remove
     *
     * @throws \InvalidArgumentException when a name is not a valid tag (OrderTag::normalize)
     * @throws \DomainException          when the order would have more than MAX_TAGS
     */
    public function changeTags(array $add, array $remove, Actor $actor, \DateTimeImmutable $now): ?OrderEvent
    {
        $before = $this->tags();

        foreach ($remove as $name) {
            $name = OrderTag::normalize($name);
            foreach ($this->tags as $tag) {
                if (OrderTag::same($tag->name(), $name)) {
                    $this->tags->removeElement($tag);
                }
            }
        }
        foreach ($add as $name) {
            $name = OrderTag::normalize($name);
            if (!$this->hasTag($name)) {
                $this->tags->add(new OrderTag($this, $name));
            }
        }

        if ($this->tags->count() > self::MAX_TAGS) {
            throw new \DomainException(\sprintf('Order %s would have more than %d tags.', $this->number, self::MAX_TAGS));
        }

        $after = $this->tags();
        if ($after === $before) {
            return null;
        }

        $event = new OrderEvent($this, OrderEvent::TAGS_CHANGED, null, $actor, ['tags' => $before], ['tags' => $after], $now);
        $this->events->add($event);

        return $event;
    }

    public function hasTag(string $name): bool
    {
        foreach ($this->tags as $tag) {
            if (OrderTag::same($tag->name(), $name)) {
                return true;
            }
        }

        return false;
    }

    /** Set by hand in Phase 1; any status may follow any other. Null when it is already that. */
    public function changePaymentStatus(PaymentStatus $status, Actor $actor, \DateTimeImmutable $now): ?OrderEvent
    {
        if ($status === $this->paymentStatus) {
            return null;
        }

        $before = ['paymentStatus' => $this->paymentStatus->value];
        $this->paymentStatus = $status;
        $this->updatedAt = $now;

        $event = new OrderEvent($this, OrderEvent::PAYMENT_STATUS_CHANGED, null, $actor, $before, ['paymentStatus' => $status->value], $now);
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

    /**
     * Whether the order can be edited now: pending, confirmed or allocated
     * (or on hold from one of those), and nothing has shipped. Once picking
     * starts, the warehouse works from what was printed.
     */
    public function canEdit(): bool
    {
        $status = OrderStatus::OnHold === $this->status ? $this->heldFrom : $this->status;

        return \in_array($status, self::EDITABLE, true) && !$this->hasShipped();
    }

    /**
     * Applies an edit. Lines' quantities change, lines go and come, and the
     * customer's name, email and addresses are replaced; the total follows.
     * One `edited` event records what changed, before and after. Returns null
     * when the edit changes nothing (no event, nothing written).
     *
     * The stock is not touched here: OrderStock::follow() moves each touched
     * line's reservation to match, in the same transaction.
     *
     * @throws OrderChangeRefused
     * @throws \OverflowException when the new total is out of range
     */
    public function edit(OrderEdit $edit, Actor $actor, \DateTimeImmutable $now): ?OrderEvent
    {
        if (!$this->canEdit()) {
            throw new OrderChangeRefused(\sprintf('Order %s cannot be edited while it is %s%s.', $this->number, str_replace('_', ' ', $this->status->value), $this->hasShipped() ? ' and partly shipped' : ''), OrderChangeRefused::NOT_EDITABLE);
        }

        $before = [];
        $after = [];

        $linesBefore = [];
        $linesAfter = [];
        foreach ($edit->quantities as $entry) {
            $line = $this->ownLine($entry['line']);
            if ($entry['quantity'] === $line->quantity()) {
                continue;
            }
            $snapshot = $line->snapshot();
            try {
                $line->changeQuantity($entry['quantity']);
            } catch (\InvalidArgumentException $e) {
                throw new OrderChangeRefused($e->getMessage(), OrderChangeRefused::BELOW_DONE, $line->position());
            }
            $linesBefore[$line->position()] = $snapshot;
            $linesAfter[$line->position()] = $line->snapshot();
        }
        foreach ($edit->removed as $line) {
            $line = $this->ownLine($line);
            if ($line->shippedQuantity() > 0) {
                throw new OrderChangeRefused(\sprintf('Line %d (%s) has shipped units and cannot be removed.', $line->position(), $line->skuCode()), OrderChangeRefused::BELOW_DONE, $line->position());
            }
            $linesBefore[$line->position()] = $line->snapshot();
            unset($linesAfter[$line->position()]);
            $this->lines->removeElement($line);
        }
        $position = array_reduce($this->lines(), static fn (int $max, OrderLine $line): int => max($max, $line->position()), 0);
        foreach ($edit->removed as $line) {
            $position = max($position, $line->position());
        }
        foreach ($edit->added as $new) {
            $line = new OrderLine($this, ++$position, $new);
            $this->lines->add($line);
            $linesAfter[$line->position()] = $line->snapshot();
        }
        if ([] !== $linesBefore || [] !== $linesAfter) {
            ksort($linesBefore);
            ksort($linesAfter);
            $before['lines'] = array_values($linesBefore);
            $after['lines'] = array_values($linesAfter);
        }

        if (null !== $edit->customerName && $edit->customerName !== $this->customerName) {
            $before['customerName'] = $this->customerName;
            $after['customerName'] = $this->customerName = $edit->customerName;
        }
        if ($edit->changeEmail && $edit->customerEmail !== $this->customerEmail) {
            $before['customerEmail'] = $this->customerEmail;
            $after['customerEmail'] = $this->customerEmail = $edit->customerEmail;
        }
        if (null !== $edit->shippingAddress && $edit->shippingAddress !== $this->shippingAddress) {
            $before['shippingAddress'] = $this->shippingAddress;
            $after['shippingAddress'] = $this->shippingAddress = $edit->shippingAddress;
        }
        if ($edit->changeBilling && $edit->billingAddress !== $this->billingAddress) {
            $before['billingAddress'] = $this->billingAddress;
            $after['billingAddress'] = $this->billingAddress = $edit->billingAddress;
        }

        if ([] === $after && [] === $before) {
            return null;
        }
        if (0 === $this->remainingUnits()) {
            throw new OrderChangeRefused(\sprintf('The edit would leave order %s with nothing to ship; cancel the order instead.', $this->number), OrderChangeRefused::NOTHING_LEFT);
        }

        $total = $this->totalAmount;
        $this->recalculateTotal();
        if ($total !== $this->totalAmount) {
            $before['total'] = $total;
            $after['total'] = $this->totalAmount;
        }
        $this->updatedAt = $now;

        $event = new OrderEvent($this, OrderEvent::EDITED, null, $actor, $before, $after, $now);
        $this->events->add($event);

        return $event;
    }

    /**
     * Fixes a shipment's carrier and tracking number, typed wrong. Allowed
     * whatever the order's status: it changes no stock and no quantity.
     */
    public function correctShipment(Shipment $shipment, ?string $carrier, ?string $trackingNumber, Actor $actor, \DateTimeImmutable $now): ?OrderEvent
    {
        $this->assertOwn($shipment);
        if ($shipment->isVoided()) {
            throw new ShipmentRefused('A voided shipment is not corrected.', ShipmentRefused::NOT_VOIDABLE);
        }
        $before = ['carrier' => $shipment->carrier(), 'trackingNumber' => $shipment->trackingNumber()];
        $after = ['carrier' => $carrier, 'trackingNumber' => $trackingNumber];
        if ($before === $after) {
            return null;
        }

        $shipment->correct($carrier, $trackingNumber);
        $this->updatedAt = $now;
        $event = new OrderEvent($this, OrderEvent::SHIPMENT_CORRECTED, null, $actor, ['shipment' => (string) $shipment->id(), ...$before], ['shipment' => (string) $shipment->id(), ...$after], $now);
        $this->events->add($event);

        return $event;
    }

    /** Whether some units can be cancelled now: the order could be cancelled, and has units left to ship. */
    public function canCancelUnits(): bool
    {
        return null !== OrderStateMachine::target($this->status, Transition::Cancel, $this->heldFrom) && $this->remainingUnits() > 0;
    }

    /**
     * Cancels some units of some lines, but never what has shipped. The units
     * stop being reserved and leave the totals; one `lines_cancelled` event
     * records it. When nothing is left to ship, the order finishes through the
     * state machine: shipped if any of it shipped, otherwise cancelled.
     *
     * @param non-empty-list<array{line: OrderLine, quantity: int}> $lines lines of this order
     *
     * @return list<array{line: OrderLine, released: int}> the reserved units each line gave up, for OrderStock to release
     *
     * @throws OrderChangeRefused
     */
    public function cancelUnits(array $lines, ?string $reason, Actor $actor, \DateTimeImmutable $now): array
    {
        if (!$this->canCancelUnits()) {
            throw new OrderChangeRefused(\sprintf('Order %s has nothing to cancel while it is %s.', $this->number, str_replace('_', ' ', $this->status->value)), OrderChangeRefused::NOT_CANCELLABLE);
        }

        $cancelling = [];
        foreach ($lines as $entry) {
            $line = $this->ownLine($entry['line']);
            $cancelling[$line->position()] = ($cancelling[$line->position()] ?? 0) + $entry['quantity'];
            if ($entry['quantity'] < 1 || $cancelling[$line->position()] > $line->remainingQuantity()) {
                throw new OrderChangeRefused(\sprintf('Line %d (%s) has %d left to cancel.', $line->position(), $line->skuCode(), $line->remainingQuantity()), OrderChangeRefused::EXCEEDS_REMAINING, $line->position());
            }
        }
        if (array_sum($cancelling) === $this->remainingUnits() && OrderStatus::OnHold === $this->status && $this->hasShipped()) {
            // It would finish as shipped, and shipped is not a status an order
            // on hold can move to: the hold is someone's decision to undo first.
            throw new OrderChangeRefused(\sprintf('Order %s is on hold; release it before cancelling the last of it.', $this->number), OrderChangeRefused::RELEASE_FIRST);
        }

        $total = $this->totalAmount;
        $before = [];
        $after = [];
        $released = [];
        foreach ($lines as $entry) {
            $line = $entry['line'];
            $before[] = ['position' => $line->position(), 'sku' => $line->skuCode(), 'cancelledQuantity' => $line->cancelledQuantity()];
            $released[] = ['line' => $line, 'released' => $line->cancel($entry['quantity'])];
            $after[] = ['position' => $line->position(), 'sku' => $line->skuCode(), 'name' => $line->name(), 'cancelled' => $entry['quantity'], 'cancelledQuantity' => $line->cancelledQuantity()];
        }
        $this->recalculateTotal();
        $this->updatedAt = $now;

        $this->events->add(new OrderEvent(
            $this,
            OrderEvent::LINES_CANCELLED,
            null,
            $actor,
            ['lines' => $before, 'total' => $total],
            ['lines' => $after, 'total' => $this->totalAmount, ...(null === $reason ? [] : ['reason' => $reason])],
            $now,
        ));

        if (0 === $this->remainingUnits()) {
            $this->apply($this->hasShipped() ? Transition::Ship : Transition::Cancel, $actor, $now);
        }

        return $released;
    }

    /**
     * Takes back a shipment recorded by mistake: its units are unshipped and
     * reserved again (the stock is OrderStock's, in the same transaction), the
     * shipment is marked void and kept, and an order that had shipped goes
     * back to where it shipped from. A delivered order is a return, not this.
     *
     * @throws ShipmentRefused
     */
    public function voidShipment(Shipment $shipment, ?string $reason, Actor $actor, \DateTimeImmutable $now): void
    {
        $this->assertOwn($shipment);
        if ($shipment->isVoided()) {
            throw new ShipmentRefused('This shipment is already voided.', ShipmentRefused::NOT_VOIDABLE);
        }
        if (OrderStatus::Shipped !== $this->status && !\in_array($this->status, self::HOLDING_STOCK, true)) {
            throw new ShipmentRefused(\sprintf('A shipment of an order that is %s cannot be voided; a delivered order comes back as a return.', $this->status->value), ShipmentRefused::NOT_VOIDABLE);
        }

        foreach ($shipment->lines() as $line) {
            $line->orderLine()->markUnshipped($line->quantity());
        }
        $shipment->void($reason, $actor, $now);
        $this->updatedAt = $now;
        $this->events->add(new OrderEvent($this, OrderEvent::SHIPMENT_VOIDED, null, $actor, null, [
            'shipment' => (string) $shipment->id(),
            'reason' => $reason,
            'lines' => array_map(static fn (ShipmentLine $line): array => ['position' => $line->orderLine()->position(), 'sku' => $line->orderLine()->skuCode(), 'quantity' => $line->quantity()], $shipment->lines()),
        ], $now));

        if (OrderStatus::Shipped === $this->status) {
            // Orders shipped before `shipped_from` existed go back to packed.
            $this->shippedFrom ??= OrderStatus::Packed;
            $this->apply(Transition::Reopen, $actor, $now);
        }
    }

    /** Whether a shipment can be voided now (it is not already, and the order is not delivered or cancelled). */
    public function canVoid(Shipment $shipment): bool
    {
        return !$shipment->isVoided() && (OrderStatus::Shipped === $this->status || \in_array($this->status, self::HOLDING_STOCK, true));
    }

    public function shipmentById(string $id): ?Shipment
    {
        foreach ($this->shipments as $shipment) {
            if ((string) $shipment->id() === $id) {
                return $shipment;
            }
        }

        return null;
    }

    private function assertOwn(Shipment $shipment): void
    {
        if (!$this->shipments->contains($shipment)) {
            throw new \InvalidArgumentException(\sprintf('That shipment is not one of order %s\'s.', $this->number));
        }
    }

    /** Whether a shipment can be recorded now: the order holds stock, is not on hold, and has units left. */
    public function canShip(): bool
    {
        return \in_array($this->status, self::HOLDING_STOCK, true) && $this->remainingUnits() > 0;
    }

    /** Whether any of the order has left in a shipment. */
    public function hasShipped(): bool
    {
        return $this->shipments->exists(static fn (int $key, Shipment $shipment): bool => !$shipment->isVoided());
    }

    /** Units still to ship, over all lines: neither shipped nor cancelled. */
    public function remainingUnits(): int
    {
        return array_sum(array_map(static fn (OrderLine $line): int => $line->remainingQuantity(), $this->lines()));
    }

    private function ownLine(OrderLine $line): OrderLine
    {
        if (!$this->lines->contains($line)) {
            throw new \InvalidArgumentException(\sprintf('Line %d is not a line of order %s.', $line->position(), $this->number));
        }

        return $line;
    }

    private function recalculateTotal(): void
    {
        $total = Money::zero($this->currency);
        foreach ($this->lines as $line) {
            $total = $total->add($line->lineTotal());
        }
        $this->totalAmount = $total->amount;
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
            fn (Transition $transition): bool => !\in_array($transition, [Transition::Ship, Transition::Reopen], true) && !(Transition::Cancel === $transition && $this->hasShipped()),
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
            'paymentStatus' => $this->paymentStatus->value,
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

    public function paymentStatus(): PaymentStatus
    {
        return $this->paymentStatus;
    }

    /** @return list<string> in alphabetical order, ignoring case */
    public function tags(): array
    {
        $names = array_map(static fn (OrderTag $tag): string => $tag->name(), array_values($this->tags->toArray()));
        usort($names, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return $names;
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
