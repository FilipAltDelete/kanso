<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Support;

use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Inventory\InventoryLevel;
use Kanso\Core\Internal\Domain\Inventory\InventoryMovement;
use Kanso\Core\Internal\Domain\Inventory\InventoryStoreInterface;
use Kanso\Core\Internal\Domain\Inventory\Location;

/**
 * Levels and movements in arrays (products and locations in the two stores
 * beside them), with a transaction
 * that really rolls back: what a unit of work added is dropped when it throws.
 * Enough to test the inventory use cases without MySQL.
 */
final class InMemoryInventory implements InventoryStoreInterface, TransactionInterface
{
    public InMemoryProducts $products;
    public InMemoryLocations $locations;
    /** @var list<InventoryLevel> */
    public array $levels = [];
    /** @var list<InventoryMovement> */
    public array $movements = [];

    /** Makes the next commit lose a race, as a stale optimistic lock does. */
    public bool $loseNextRace = false;

    public function __construct()
    {
        $this->products = new InMemoryProducts();
        $this->locations = new InMemoryLocations();
    }

    public function run(callable $work): mixed
    {
        $levels = $this->levels;
        $movements = $this->movements;

        try {
            $result = $work();
            if ($this->loseNextRace) {
                $this->loseNextRace = false;
                throw new ConcurrentModification('Row was updated by another transaction.');
            }

            return $result;
        } catch (\Throwable $e) {
            $this->levels = $levels;
            $this->movements = $movements;

            throw $e;
        }
    }

    public function findLevel(Product $product, Location $location): ?InventoryLevel
    {
        foreach ($this->levels as $level) {
            if ($level->product() === $product && $level->location() === $location) {
                return $level;
            }
        }

        return null;
    }

    /** No other writers in memory, so a lock is a plain read. */
    public function lockLevel(Product $product, Location $location): ?InventoryLevel
    {
        return $this->findLevel($product, $location);
    }

    public function findLevelById(string $id): ?InventoryLevel
    {
        return null;
    }

    public function levels(PageRequest $request): Page
    {
        return new Page($this->levels, \count($this->levels));
    }

    public function totals(array $productIds): array
    {
        return [];
    }

    public function findMovementById(string $id): ?InventoryMovement
    {
        return null;
    }

    public function movements(PageRequest $request): Page
    {
        return new Page(array_reverse($this->movements), \count($this->movements));
    }

    public function addLevel(InventoryLevel $level): void
    {
        $this->levels[] = $level;
    }

    public function addMovement(InventoryMovement $movement): void
    {
        $this->movements[] = $movement;
    }
}
