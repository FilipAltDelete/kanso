<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

use Doctrine\ORM\Mapping as ORM;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The stock of one product at one location, and the only place its arithmetic
 * happens.
 *
 * `available = on_hand − reserved`, and the invariants that keep that
 * meaningful are enforced here, not by callers:
 *
 *   0 ≤ reserved ≤ on_hand
 *
 * so available is never negative. Stock that is reserved for an order cannot
 * be adjusted away; release the reservation first.
 *
 * Every change returns a `StockChange` for the movement that records it, and
 * `version` is Doctrine's optimistic lock: two writers that read the same
 * version cannot both save.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_level')]
#[ORM\UniqueConstraint(name: 'uq_inventory_level_product_location', columns: ['product_id', 'location_id'])]
class InventoryLevel
{
    /** A sanity bound, far above any real stock and well inside a signed INT. */
    public const int MAX_QUANTITY = 1_000_000_000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false)]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: false)]
    private Location $location;

    #[ORM\Column(name: 'on_hand')]
    private int $onHand = 0;

    #[ORM\Column]
    private int $reserved = 0;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Product $product, Location $location, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->product = $product;
        $this->location = $location;
        $this->updatedAt = $now;
    }

    /** Adds (positive) or removes (negative) stock. */
    public function adjustBy(int $delta, \DateTimeImmutable $now): StockChange
    {
        if (0 === $delta) {
            throw new StockRuleViolated('An adjustment has to change the quantity.', StockRuleViolated::NO_CHANGE);
        }

        return $this->setOnHand($this->onHand + $delta, $now);
    }

    /**
     * Sets on hand to what was counted. A count that matches is still
     * recorded: "counted, and it was right" is part of the history.
     */
    public function countAs(int $counted, \DateTimeImmutable $now): StockChange
    {
        return $this->setOnHand($counted, $now);
    }

    /** Holds stock for an order; it stays on hand but is no longer available. */
    public function reserve(int $quantity, \DateTimeImmutable $now): StockChange
    {
        self::assertPositive($quantity);
        if ($quantity > $this->available()) {
            throw new StockRuleViolated(\sprintf('Only %d available; cannot reserve %d.', $this->available(), $quantity), StockRuleViolated::INSUFFICIENT_AVAILABLE);
        }

        return $this->apply($this->onHand, $this->reserved + $quantity, $now);
    }

    /** Gives reserved stock back to available, when an order is cancelled or changed. */
    public function release(int $quantity, \DateTimeImmutable $now): StockChange
    {
        self::assertPositive($quantity);
        if ($quantity > $this->reserved) {
            throw new StockRuleViolated(\sprintf('Only %d reserved; cannot release %d.', $this->reserved, $quantity), StockRuleViolated::OVER_RELEASE);
        }

        return $this->apply($this->onHand, $this->reserved - $quantity, $now);
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

    public function onHand(): int
    {
        return $this->onHand;
    }

    public function reserved(): int
    {
        return $this->reserved;
    }

    public function available(): int
    {
        return $this->onHand - $this->reserved;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function setOnHand(int $onHand, \DateTimeImmutable $now): StockChange
    {
        if ($onHand > self::MAX_QUANTITY) {
            throw new StockRuleViolated(\sprintf('On hand cannot exceed %d.', self::MAX_QUANTITY), StockRuleViolated::TOO_LARGE);
        }
        if ($onHand < 0) {
            throw new StockRuleViolated(\sprintf('On hand cannot go below zero (it would be %d).', $onHand), StockRuleViolated::BELOW_ZERO);
        }
        if ($onHand < $this->reserved) {
            throw new StockRuleViolated(
                \sprintf('On hand cannot go below the %d reserved for orders (it would be %d). Release the reservation first.', $this->reserved, $onHand),
                StockRuleViolated::BELOW_RESERVED,
            );
        }

        return $this->apply($onHand, $this->reserved, $now);
    }

    private function apply(int $onHand, int $reserved, \DateTimeImmutable $now): StockChange
    {
        $change = new StockChange($this->onHand, $onHand, $this->reserved, $reserved);
        $this->onHand = $onHand;
        $this->reserved = $reserved;
        $this->updatedAt = $now;

        return $change;
    }

    private static function assertPositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new StockRuleViolated('The quantity has to be at least 1.', StockRuleViolated::NOT_POSITIVE);
        }
    }
}
