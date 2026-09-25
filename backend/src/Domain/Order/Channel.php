<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Where orders come from: `manual` (created in Kanso) now, CSV imports and
 * shop connectors later. Its currency is the default for a new order.
 */
#[ORM\Entity]
#[ORM\Table(name: 'channel')]
class Channel
{
    public const string MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $code;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $code, string $name, string $type, string $currency, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->code = $code;
        $this->name = $name;
        $this->type = $type;
        $this->currency = Money::zero($currency)->currency;
        $this->createdAt = $createdAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function currency(): string
    {
        return $this->currency;
    }
}
