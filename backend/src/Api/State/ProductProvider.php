<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\ProductResource;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Kanso\Core\Internal\Domain\Inventory\InventoryStoreInterface;

/**
 * @implements ProviderInterface<ProductResource>
 */
final class ProductProvider implements ProviderInterface
{
    public function __construct(
        private readonly ProductStoreInterface $products,
        private readonly InventoryStoreInterface $inventory,
        private readonly ListRequest $list,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            [$request, $page, $limit] = $this->list->from($operation, $context, ProductStoreInterface::SORTABLE);
            $result = $this->products->search($request);
            // One query for the stock of the whole page, not one per row.
            $totals = $this->inventory->totals(array_map(static fn (Product $product): string => $product->id()->toRfc4122(), $result->items));

            return ListRequest::paginator(
                $result,
                static fn (Product $product): ProductResource => self::present($product, $totals[$product->id()->toRfc4122()] ?? null),
                $page,
                $limit,
            );
        }

        $product = $this->products->findById((string) ($uriVariables['id'] ?? ''));
        if (null === $product) {
            return null;
        }

        return self::present($product, $this->inventory->totals([$product->id()->toRfc4122()])[$product->id()->toRfc4122()] ?? null);
    }

    /** @param array{onHand: int, reserved: int}|null $totals */
    public static function present(Product $product, ?array $totals): ProductResource
    {
        $resource = new ProductResource();
        $resource->id = $product->id()->toRfc4122();
        $resource->sku = $product->sku();
        $resource->name = $product->name();
        $resource->barcode = $product->barcode();
        $resource->weightGrams = $product->weightGrams();
        $resource->version = $product->version();
        $resource->onHand = $totals['onHand'] ?? 0;
        $resource->reserved = $totals['reserved'] ?? 0;
        $resource->available = $resource->onHand - $resource->reserved;
        $resource->createdAt = $product->createdAt();
        $resource->updatedAt = $product->updatedAt();

        return $resource;
    }
}
