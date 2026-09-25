<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Customer;

use Doctrine\ORM\Mapping as ORM;
use Kanso\Core\Internal\Domain\Security\Actor;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The audit trail of a customer: who changed what, and when, with the values
 * before and after. Written in the same transaction as the change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_event')]
#[ORM\Index(name: 'idx_customer_event_customer', columns: ['customer_id', 'occurred_at'])]
class CustomerEvent
{
    public const string CREATED = 'created';
    public const string UPDATED = 'updated';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'customer_id', type: UuidType::NAME)]
    private Uuid $customerId;

    #[ORM\Column(length: 16)]
    private string $type;

    /** The security identifier of whoever made the change; null for the console. */
    #[ORM\Column(name: 'actor_id', length: 64, nullable: true)]
    private ?string $actorId;

    /** How the actor was known at the time (an email, an API key's name), kept if they are renamed. */
    #[ORM\Column(name: 'actor_label', length: 255)]
    private string $actorLabel;

    /** @var array<string, array{before: mixed, after: mixed}> */
    #[ORM\Column(type: 'json')]
    private array $changes;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    /** @param array<string, array{before: mixed, after: mixed}> $changes */
    public function __construct(Uuid $customerId, string $type, Actor $actor, array $changes, \DateTimeImmutable $occurredAt)
    {
        $this->id = Uuid::v7();
        $this->customerId = $customerId;
        $this->type = $type;
        $this->actorId = $actor->id;
        $this->actorLabel = $actor->label;
        $this->changes = $changes;
        $this->occurredAt = $occurredAt;
    }

    /**
     * The fields that differ between two snapshots, each with its before and
     * after value. A created customer is compared with an empty snapshot.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_keys($before + $after) as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ($old !== $new) {
                $changes[$field] = ['before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function customerId(): Uuid
    {
        return $this->customerId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function actorLabel(): string
    {
        return $this->actorLabel;
    }

    /** @return array<string, array{before: mixed, after: mixed}> */
    public function changes(): array
    {
        return $this->changes;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
