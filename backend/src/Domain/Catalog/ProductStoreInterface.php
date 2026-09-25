<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Catalog;

use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;

interface ProductStoreInterface
{
    /** Fields a product list can be sorted by. */
    public const array SORTABLE = ['sku', 'name', 'createdAt', 'updatedAt'];

    public function findById(string $id): ?Product;

    public function findBySku(string $sku): ?Product;

    /**
     * @param list<string> $skus
     *
     * @return array<string, Product> the products that exist, keyed by SKU
     */
    public function findBySkus(array $skus): array;

    /**
     * Search matches the start of the SKU, any part of the name, or the whole barcode.
     *
     * @return Page<Product>
     */
    public function search(PageRequest $request): Page;

    public function add(Product $product): void;

    public function findEventById(string $id): ?ProductEvent;

    /** Stages an audit event; it is written when the surrounding transaction commits. */
    public function addEvent(ProductEvent $event): void;

    /**
     * A product's history, newest first. The `product` filter (an id) is required.
     *
     * @return Page<ProductEvent>
     */
    public function events(PageRequest $request): Page;
}
