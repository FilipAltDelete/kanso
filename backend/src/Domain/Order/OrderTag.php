<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Doctrine\ORM\Mapping as ORM;

/**
 * A free-text label on an order, for filtering and bulk work. Tags are
 * compared without regard to case (as the column's collation does), and
 * keep the spelling they were first given.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_tag')]
class OrderTag
{
    public const int MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'tags')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Id]
    #[ORM\Column(length: self::MAX_LENGTH)]
    private string $name;

    public function __construct(Order $order, string $name)
    {
        $this->order = $order;
        $this->name = self::normalize($name);
    }

    /**
     * Trimmed, inner whitespace collapsed. Refuses what cannot be a tag: an
     * empty or overlong name, or a comma (the list filter separates tags with one).
     */
    public static function normalize(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ('' === $name || mb_strlen($name) > self::MAX_LENGTH || str_contains($name, ',')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid tag: 1 to %d characters, no commas.', $name, self::MAX_LENGTH));
        }

        return $name;
    }

    public static function same(string $a, string $b): bool
    {
        return mb_strtolower($a) === mb_strtolower($b);
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function name(): string
    {
        return $this->name;
    }
}
