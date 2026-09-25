<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Order;

use Kanso\Core\Internal\Domain\Common\PageRequest;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Inventory\LocationStoreInterface;

/**
 * Where an order's stock comes from when the order does not say: the location
 * named by `KANSO_DEFAULT_LOCATION` (its code), or, when that is not set and
 * the installation has exactly one location, that one. An installation with
 * several locations and no default has to name one on every order.
 */
final class DefaultLocation
{
    public function __construct(
        private readonly LocationStoreInterface $locations,
        private readonly string $defaultLocationCode,
    ) {
    }

    /** @throws \DomainException with a message for the operator when there is none */
    public function get(): Location
    {
        $code = trim($this->defaultLocationCode);
        if ('' !== $code) {
            return $this->locations->findByCode($code)
                ?? throw new \DomainException(\sprintf('The default location "%s" (KANSO_DEFAULT_LOCATION) does not exist.', $code));
        }

        $page = $this->locations->search(new PageRequest(limit: 2));
        if (1 === $page->total) {
            return $page->items[0];
        }

        throw new \DomainException(0 === $page->total
            ? 'There are no locations yet; create one to hold stock.'
            : 'Name the location to ship from: there are several and no default (KANSO_DEFAULT_LOCATION).');
    }
}
