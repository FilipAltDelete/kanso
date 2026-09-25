<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Catalog;

use Doctrine\ORM\Mapping as ORM;
use Kanso\Core\Internal\Domain\Common\Actor;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The audit trail of a product: who created or changed it, when, through
 * what (the API or a CSV import), and its fields before and after. Written
 * in the same transaction as the change it records; never updated or
 * deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_event')]
#[ORM\Index(name: 'idx_product_event_product', columns: ['product_id', 'occurred_at'])]
class ProductEvent
{
    public const string CREATED = 'created';
    public const string UPDATED = 'updated';

    public const string SOURCE_API = 'api';
    public const string SOURCE_IMPORT = 'import';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column(length: 16)]
    private string $source;

    #[ORM\Column(length: 64)]
    private string $actor;

    #[ORM\Column(name: 'actor_name', length: 255)]
    private string $actorName;

    /** @var array<string, string|int|null>|null */
    #[ORM\Column(name: 'before_state', type: 'json', nullable: true)]
    private ?array $before;

    /** @var array<string, string|int|null> */
    #[ORM\Column(name: 'after_state', type: 'json')]
    private array $after;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, string|int|null>|null $before null for a created product
     * @param array<string, string|int|null>      $after
     */
    private function __construct(Product $product, string $type, string $source, Actor $actor, ?array $before, array $after, \DateTimeImmutable $occurredAt)
    {
        // v7: time-ordered, so the history sorts by id even within one second.
        $this->id = Uuid::v7();
        $this->product = $product;
        $this->type = $type;
        $this->source = $source;
        $this->actor = $actor->id;
        $this->actorName = $actor->name;
        $this->before = $before;
        $this->after = $after;
        $this->occurredAt = $occurredAt;
    }

    public static function created(Product $product, string $source, Actor $actor, \DateTimeImmutable $now): self
    {
        return new self($product, self::CREATED, $source, $actor, null, self::state($product), $now);
    }

    /**
     * A change from `$before` (state() taken before it) to the product as it
     * is now, or null when nothing changed and there is nothing to record.
     *
     * @param array<string, string|int|null> $before
     */
    public static function updated(Product $product, array $before, string $source, Actor $actor, \DateTimeImmutable $now): ?self
    {
        $after = self::state($product);
        if ($after === $before) {
            return null;
        }

        return new self($product, self::UPDATED, $source, $actor, $before, $after, $now);
    }

    /**
     * What the history records of a product: the fields a person can change.
     *
     * @return array{sku: string, name: string, barcode: ?string, weightGrams: ?int}
     */
    public static function state(Product $product): array
    {
        return ['sku' => $product->sku(), 'name' => $product->name(), 'barcode' => $product->barcode(), 'weightGrams' => $product->weightGrams()];
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function actor(): Actor
    {
        return new Actor($this->actor, $this->actorName);
    }

    /** @return array<string, string|int|null>|null */
    public function before(): ?array
    {
        return $this->before;
    }

    /** @return array<string, string|int|null> */
    public function after(): array
    {
        return $this->after;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
