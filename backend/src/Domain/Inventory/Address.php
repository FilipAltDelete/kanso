<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

use Doctrine\ORM\Mapping as ORM;

/** A postal address. Every part is optional: a location may be known by name alone. */
#[ORM\Embeddable]
final readonly class Address
{
    public function __construct(
        #[ORM\Column(length: 128, nullable: true)]
        public ?string $line1 = null,
        #[ORM\Column(length: 128, nullable: true)]
        public ?string $line2 = null,
        #[ORM\Column(name: 'postal_code', length: 16, nullable: true)]
        public ?string $postalCode = null,
        #[ORM\Column(length: 64, nullable: true)]
        public ?string $city = null,
        /** ISO 3166-1 alpha-2. */
        #[ORM\Column(name: 'country_code', length: 2, nullable: true)]
        public ?string $countryCode = null,
    ) {
    }
}
