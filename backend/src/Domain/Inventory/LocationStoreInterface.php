<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;

interface LocationStoreInterface
{
    /** Fields a location list can be sorted by. */
    public const array SORTABLE = ['code', 'name', 'city'];

    public function findById(string $id): ?Location;

    public function findByCode(string $code): ?Location;

    /**
     * Search matches the start of the code, or any part of the name or city.
     *
     * @return Page<Location>
     */
    public function search(PageRequest $request): Page;

    public function add(Location $location): void;
}
