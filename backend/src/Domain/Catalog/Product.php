<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Catalog;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What is sold and stocked, one SKU per row. The SKU is fixed once created:
 * order lines, channel listings and stock all refer to it.
 *
 * Weight is whole grams, never a float — the same rule as money.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product')]
#[ORM\Index(name: 'idx_product_barcode', columns: ['barcode'])]
#[ORM\Index(name: 'idx_product_name', columns: ['name'])]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $sku;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $barcode;

    #[ORM\Column(name: 'weight_grams', nullable: true)]
    private ?int $weightGrams;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $sku, string $name, ?string $barcode, ?int $weightGrams, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->sku = $sku;
        $this->name = $name;
        $this->barcode = $barcode;
        $this->weightGrams = $weightGrams;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function update(string $name, ?string $barcode, ?int $weightGrams, \DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->barcode = $barcode;
        $this->weightGrams = $weightGrams;
        $this->updatedAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function barcode(): ?string
    {
        return $this->barcode;
    }

    public function weightGrams(): ?int
    {
        return $this->weightGrams;
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
