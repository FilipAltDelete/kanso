<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Doctrine\ORM\Mapping as ORM;
use Kanso\Core\Internal\Domain\Common\Actor;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The audit trail of an order: who, what, when, and the state before and
 * after. Only Order creates these, in the same flush (so the same
 * transaction) as the change they record. Never updated or deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_event')]
class OrderEvent
{
    public const string CREATED = 'created';
    public const string TRANSITION = 'transition';
    public const string SHIPMENT = 'shipment';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $transition;

    #[ORM\Column(length: 64)]
    private string $actor;

    #[ORM\Column(name: 'actor_name', length: 255)]
    private string $actorName;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'before_state', type: 'json', nullable: true)]
    private ?array $before;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'after_state', type: 'json', nullable: true)]
    private ?array $after;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function __construct(
        Order $order,
        string $type,
        ?Transition $transition,
        Actor $actor,
        ?array $before,
        ?array $after,
        \DateTimeImmutable $occurredAt,
    ) {
        // v7: time-ordered, so the timeline sorts by id even within one second.
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->type = $type;
        $this->transition = $transition?->value;
        $this->actor = $actor->id;
        $this->actorName = $actor->name;
        $this->before = $before;
        $this->after = $after;
        $this->occurredAt = $occurredAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function transition(): ?string
    {
        return $this->transition;
    }

    public function actor(): Actor
    {
        return new Actor($this->actor, $this->actorName);
    }

    /** @return array<string, mixed>|null */
    public function before(): ?array
    {
        return $this->before;
    }

    /** @return array<string, mixed>|null */
    public function after(): ?array
    {
        return $this->after;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
