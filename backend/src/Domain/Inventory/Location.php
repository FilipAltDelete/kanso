<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A warehouse or store that holds stock. The code is fixed once created: it is
 * what integrations and pick lists name the location by.
 */
#[ORM\Entity]
#[ORM\Table(name: 'location')]
class Location
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 32, unique: true)]
    private string $code;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Embedded(class: Address::class, columnPrefix: 'address_')]
    private Address $address;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, Address $address, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->code = $code;
        $this->name = $name;
        $this->address = $address;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function update(string $name, Address $address, \DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->address = $address;
        $this->updatedAt = $now;
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

    public function address(): Address
    {
        return $this->address;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
