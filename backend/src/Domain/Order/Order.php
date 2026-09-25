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
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order')]
class Order
{
    public const int MAX_TAGS = 20;
    public const int MAX_NOTE_LENGTH = 2000;

    /** The statuses in which the order's stock is reserved. */
    private const array HOLDING_STOCK = [OrderStatus::Confirmed, OrderStatus::Allocated, OrderStatus::Picking, OrderStatus::Packed];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 32, unique: true)]
    private string $number;

    #[ORM\ManyToOne(targetEntity: Channel::class)]
    #[ORM\JoinColumn(name: 'channel_id', nullable: false)]
    private Channel $channel;

    #[ORM\Column(length: 16, enumType: OrderStatus::class)]
    private OrderStatus $status;

    /** Where an on-hold order returns to on release. */
    #[ORM\Column(name: 'held_from', length: 16, nullable: true, enumType: OrderStatus::class)]
    private ?OrderStatus $heldFrom = null;

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
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

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
    ): self {
        $order = new self($number, $channel, $currency, $location, $customer, $lines, $placedAt, $now);
        $order->events->add(new OrderEvent($order, OrderEvent::CREATED, null, $actor, null, $order->snapshot(), $now));

        return $order;
    }

    /** The one way to change an order's status. */
    public function apply(Transition $transition, Actor $actor, \DateTimeImmutable $now): OrderEvent
    {
        $target = OrderStateMachine::target($this->status, $transition, $this->heldFrom);
        if (null === $target) {
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

    /** @return list<Transition> */
    public function availableTransitions(): array
    {
        return OrderStateMachine::available($this->status, $this->heldFrom);
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
            'currency' => $this->currency,
            'total' => $this->totalAmount,
            'lines' => $this->lines->count(),
        ];
    }

    public function id(): Uuid
    {
        return $this->id;
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
