<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Customer;

/**
 * A customer as a caller sends it, before validation. Addresses stay loose
 * arrays until CustomerService has checked them, so a bad one is reported as
 * a violation with a path rather than a type error.
 */
final readonly class CustomerInput
{
    /** @param list<mixed> $addresses */
    public function __construct(
        public mixed $email,
        public mixed $name,
        public mixed $phone = null,
        public array $addresses = [],
    ) {
    }
}
