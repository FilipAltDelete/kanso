<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/**
 * The buyer as copied onto the order. `id` optionally points at a customer
 * record; there is no customer table yet, so it is not a foreign key.
 * Addresses are the normalized arrays the application layer validated
 * (name, line1, line2, postalCode, city, region, countryCode, phone).
 */
final readonly class OrderCustomer
{
    /**
     * @param array<string, string|null>      $shippingAddress
     * @param array<string, string|null>|null $billingAddress
     */
    public function __construct(
        public ?string $id,
        public string $name,
        public ?string $email,
        public array $shippingAddress,
        public ?array $billingAddress,
    ) {
    }
}
