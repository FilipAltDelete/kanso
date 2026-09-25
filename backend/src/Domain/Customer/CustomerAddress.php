<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Customer;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A billing or shipping address. A customer can have several of each; exactly
 * one per type is the default once there is any.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_address')]
class CustomerAddress
{
    public const string BILLING = 'billing';
    public const string SHIPPING = 'shipping';
    public const array TYPES = [self::BILLING, self::SHIPPING];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 16)]
    private string $type = self::SHIPPING;

    #[ORM\Column(name: 'is_default')]
    private bool $default = false;

    /** The recipient, when it is not the customer. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(length: 255)]
    private string $line1 = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $line2 = null;

    #[ORM\Column(name: 'postal_code', length: 32)]
    private string $postalCode = '';

    #[ORM\Column(length: 128)]
    private string $city = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $region = null;

    /** ISO 3166-1 alpha-2. */
    #[ORM\Column(name: 'country_code', length: 2)]
    private string $countryCode = '';

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone = null;

    public function __construct(Customer $customer)
    {
        $this->id = Uuid::v7();
        $this->customer = $customer;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function customer(): Customer
    {
        return $this->customer;
    }

    public function change(
        string $type,
        bool $default,
        ?string $name,
        ?string $company,
        string $line1,
        ?string $line2,
        string $postalCode,
        string $city,
        ?string $region,
        string $countryCode,
        ?string $phone,
    ): void {
        $this->type = $type;
        $this->default = $default;
        $this->name = $name;
        $this->company = $company;
        $this->line1 = $line1;
        $this->line2 = $line2;
        $this->postalCode = $postalCode;
        $this->city = $city;
        $this->region = $region;
        $this->countryCode = $countryCode;
        $this->phone = $phone;
    }

    public function markDefault(): void
    {
        $this->default = true;
    }

    public function placeAt(int $position): void
    {
        $this->position = $position;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function company(): ?string
    {
        return $this->company;
    }

    public function line1(): string
    {
        return $this->line1;
    }

    public function line2(): ?string
    {
        return $this->line2;
    }

    public function postalCode(): string
    {
        return $this->postalCode;
    }

    public function city(): string
    {
        return $this->city;
    }

    public function region(): ?string
    {
        return $this->region;
    }

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    /** @return array<string, string|bool|null> */
    public function snapshot(): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'isDefault' => $this->default,
            'name' => $this->name,
            'company' => $this->company,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'region' => $this->region,
            'countryCode' => $this->countryCode,
            'phone' => $this->phone,
        ];
    }
}
