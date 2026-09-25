<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Customer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A buyer. The email is unique regardless of case: `email` keeps what was
 * entered, `email_canonical` (lower-cased) carries the unique key.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer')]
class Customer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(name: 'email_canonical', length: 180, unique: true)]
    private string $emailCanonical;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone;

    /** @var Collection<int, CustomerAddress> */
    #[ORM\OneToMany(targetEntity: CustomerAddress::class, mappedBy: 'customer', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $addresses;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $name, ?string $phone, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->emailCanonical = self::canonicalEmail($email);
        $this->name = $name;
        $this->phone = $phone;
        $this->addresses = new ArrayCollection();
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public static function canonicalEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<CustomerAddress> */
    public function addresses(): array
    {
        return array_values($this->addresses->toArray());
    }

    public function address(string $id): ?CustomerAddress
    {
        foreach ($this->addresses as $address) {
            if ((string) $address->id() === $id) {
                return $address;
            }
        }

        return null;
    }

    public function changeDetails(string $email, string $name, ?string $phone, \DateTimeImmutable $now): void
    {
        $this->email = $email;
        $this->emailCanonical = self::canonicalEmail($email);
        $this->name = $name;
        $this->phone = $phone;
        $this->updatedAt = $now;
    }

    /**
     * The customer's addresses become exactly these, in this order. Addresses
     * already on the customer keep their ids; any not in the list are removed.
     *
     * @param list<CustomerAddress> $addresses
     */
    public function replaceAddresses(array $addresses): void
    {
        foreach ($this->addresses->toArray() as $existing) {
            if (!\in_array($existing, $addresses, true)) {
                $this->addresses->removeElement($existing);
            }
        }

        foreach ($addresses as $position => $address) {
            \assert($address->customer() === $this);
            $address->placeAt($position);
            if (!$this->addresses->contains($address)) {
                $this->addresses->add($address);
            }
        }
    }

    /**
     * What the audit trail compares before and after a change.
     *
     * @return array{email: string, name: string, phone: ?string, addresses: list<array<string, string|bool|null>>}
     */
    public function snapshot(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
            'phone' => $this->phone,
            'addresses' => array_map(static fn (CustomerAddress $address): array => $address->snapshot(), $this->addresses()),
        ];
    }
}
