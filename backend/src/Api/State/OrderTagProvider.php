<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\OrderTagResource;
use Kanso\Core\Internal\Application\Order\OrderAnnotationService;

/**
 * @implements ProviderInterface<OrderTagResource>
 */
final class OrderTagProvider implements ProviderInterface
{
    public function __construct(private readonly OrderAnnotationService $annotations)
    {
    }

    /** @return list<OrderTagResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(static function (array $tag): OrderTagResource {
            $resource = new OrderTagResource();
            $resource->name = $tag['name'];
            $resource->orders = $tag['orders'];

            return $resource;
        }, $this->annotations->tags());
    }
}
