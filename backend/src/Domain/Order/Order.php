<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Kanso\Core\Internal\Domain\Common\Actor;
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

    #[ORM\Column(length: 3)]
    private string $currency;

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

    /** @param list<NewOrderLine> $lines */
    private function __construct(string $number, Channel $channel, string $currency, OrderCustomer $customer, array $lines, \DateTimeImmutable $placedAt, \DateTimeImmutable $now)
    {
        if ([] === $lines) {
            throw new \InvalidArgumentException('An order needs at least one line.');
        }

        $this->id = Uuid::v7();
        $this->number = $number;
        $this->channel = $channel;
        $this->status = OrderStatus::Pending;
        $this->currency = Money::zero($currency)->currency;
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
        OrderCustomer $customer,
        array $lines,
        \DateTimeImmutable $placedAt,
        Actor $actor,
        \DateTimeImmutable $now,
    ): self {
        $order = new self($number, $channel, $currency, $customer, $lines, $placedAt, $now);
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

        // TODO(inventory): confirming must reserve stock in this same
        // transaction (CLAUDE.md). Deferred until Product and InventoryLevel
        // exist; until then confirm changes the status only.

        $before = $this->statusState();
        $this->heldFrom = OrderStatus::OnHold === $target ? $this->status : null;
        $this->status = $target;
        $this->updatedAt = $now;

        $event = new OrderEvent($this, OrderEvent::TRANSITION, $transition, $actor, $before, $this->statusState(), $now);
        $this->events->add($event);

        return $event;
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

    public function currency(): string
    {
        return $this->currency;
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
