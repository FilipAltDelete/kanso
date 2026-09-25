<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Support;

use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;

final class InMemoryProducts implements ProductStoreInterface
{
    /** @var array<string, Product> */
    public array $items = [];

    public function findById(string $id): ?Product
    {
        return $this->items[$id] ?? null;
    }

    public function findBySku(string $sku): ?Product
    {
        foreach ($this->items as $product) {
            if ($product->sku() === $sku) {
                return $product;
            }
        }

        return null;
    }

    public function search(PageRequest $request): Page
    {
        return new Page(array_values($this->items), \count($this->items));
    }

    public function add(Product $product): void
    {
        $this->items[$product->id()->toRfc4122()] = $product;
    }
}
