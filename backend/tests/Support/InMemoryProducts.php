<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Support;

use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Catalog\ProductEvent;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;

final class InMemoryProducts implements ProductStoreInterface
{
    /** @var array<string, Product> */
    public array $items = [];

    /** @var list<ProductEvent> */
    public array $events = [];

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

    public function findBySkus(array $skus): array
    {
        $found = [];
        foreach ($this->items as $product) {
            if (\in_array($product->sku(), $skus, true)) {
                $found[$product->sku()] = $product;
            }
        }

        return $found;
    }

    public function search(PageRequest $request): Page
    {
        return new Page(array_values($this->items), \count($this->items));
    }

    public function add(Product $product): void
    {
        $this->items[$product->id()->toRfc4122()] = $product;
    }

    public function findEventById(string $id): ?ProductEvent
    {
        foreach ($this->events as $event) {
            if ($event->id()->toRfc4122() === $id) {
                return $event;
            }
        }

        return null;
    }

    public function addEvent(ProductEvent $event): void
    {
        $this->events[] = $event;
    }

    public function events(PageRequest $request): Page
    {
        $events = array_values(array_filter($this->events, static fn (ProductEvent $event): bool => $event->product()->id()->toRfc4122() === ($request->filters['product'] ?? null)));

        return new Page(array_reverse($events), \count($events));
    }
}
