<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;

interface InventoryStoreInterface
{
    public function findLevel(Product $product, Location $location): ?InventoryLevel;

    public function findLevelById(string $id): ?InventoryLevel;

    /**
     * Filters: `product` and `location`, by id.
     *
     * @return Page<InventoryLevel>
     */
    public function levels(PageRequest $request): Page;

    /**
     * Stock summed over every location, per product.
     *
     * @param list<string> $productIds
     *
     * @return array<string, array{onHand: int, reserved: int}> by product id; products with no stock are absent
     */
    public function totals(array $productIds): array;

    public function findMovementById(string $id): ?InventoryMovement;

    /**
     * Newest first. Filters: `product` and `location`, by id.
     *
     * @return Page<InventoryMovement>
     */
    public function movements(PageRequest $request): Page;

    public function addLevel(InventoryLevel $level): void;

    public function addMovement(InventoryMovement $movement): void;
}
