<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Customer;

/** Two customers with one email: caught by the unique key when a check-then-write race loses. */
final class DuplicateCustomerEmail extends \DomainException
{
}
