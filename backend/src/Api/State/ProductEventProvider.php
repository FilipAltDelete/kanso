<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\ProductEventResource;
use Kanso\Core\Internal\Domain\Catalog\ProductEvent;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;

/**
 * @implements ProviderInterface<ProductEventResource>
 */
final class ProductEventProvider implements ProviderInterface
{
    public function __construct(
        private readonly ProductStoreInterface $products,
        private readonly ListRequest $list,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            [$request, $page, $limit] = $this->list->from($operation, $context, filterable: ['product']);

            return ListRequest::paginator($this->products->events($request), self::present(...), $page, $limit);
        }

        $event = $this->products->findEventById((string) ($uriVariables['id'] ?? ''));

        return null === $event ? null : self::present($event);
    }

    public static function present(ProductEvent $event): ProductEventResource
    {
        $resource = new ProductEventResource();
        $resource->id = $event->id()->toRfc4122();
        $resource->productId = $event->product()->id()->toRfc4122();
        $resource->type = $event->type();
        $resource->source = $event->source();
        $resource->actorId = $event->actor()->id;
        $resource->actorName = $event->actor()->name;
        $resource->before = $event->before();
        $resource->after = $event->after();
        $resource->occurredAt = $event->occurredAt();

        return $resource;
    }
}
