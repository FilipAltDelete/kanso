<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\ProductInput;
use Kanso\Core\Internal\Api\Resource\ProductPatch;
use Kanso\Core\Internal\Api\Resource\ProductResource;
use Kanso\Core\Internal\Application\Catalog\CatalogService;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<ProductInput|ProductPatch, ProductResource>
 */
final class ProductProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly ProductStoreInterface $products,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        if ($data instanceof ProductInput) {
            return ProductProvider::present($this->catalog->createProduct($data->sku, $data->name, $data->barcode, $data->weightGrams), null);
        }

        \assert($data instanceof ProductPatch);
        $product = $this->products->findById((string) ($uriVariables['id'] ?? '')) ?? throw new NotFoundHttpException('No such product.');

        if (!Sent::has($data, 'version')) {
            throw new ValidationFailed([['path' => 'version', 'message' => 'Send the version you edited, so a change made in between is not overwritten.', 'code' => 'required']]);
        }

        $updated = $this->catalog->updateProduct(
            $product,
            $data->version,
            Sent::has($data, 'name') ? $data->name : $product->name(),
            Sent::has($data, 'barcode') ? $data->barcode : $product->barcode(),
            Sent::has($data, 'weightGrams') ? $data->weightGrams : $product->weightGrams(),
        );

        $previous = $context['previous_data'] ?? null;

        return ProductProvider::present($updated, $previous instanceof ProductResource ? ['onHand' => $previous->onHand, 'reserved' => $previous->reserved] : null);
    }
}
