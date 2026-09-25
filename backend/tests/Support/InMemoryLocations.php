<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Support;

use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Inventory\LocationStoreInterface;

final class InMemoryLocations implements LocationStoreInterface
{
    /** @var array<string, Location> */
    public array $items = [];

    public function findById(string $id): ?Location
    {
        return $this->items[$id] ?? null;
    }

    public function findByCode(string $code): ?Location
    {
        foreach ($this->items as $location) {
            if ($location->code() === $code) {
                return $location;
            }
        }

        return null;
    }

    public function search(PageRequest $request): Page
    {
        return new Page(array_values($this->items), \count($this->items));
    }

    public function add(Location $location): void
    {
        $this->items[$location->id()->toRfc4122()] = $location;
    }
}
