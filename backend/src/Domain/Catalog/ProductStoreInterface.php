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
     * Search matches the start of the SKU, any part of the name, or the whole barcode.
     *
     * @return Page<Product>
     */
    public function search(PageRequest $request): Page;

    public function add(Product $product): void;
}
