<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\InventoryLevelResource;
use Kanso\Core\Internal\Domain\Inventory\InventoryLevel;
use Kanso\Core\Internal\Domain\Inventory\InventoryStoreInterface;

/**
 * @implements ProviderInterface<InventoryLevelResource>
 */
final class InventoryLevelProvider implements ProviderInterface
{
    public function __construct(
        private readonly InventoryStoreInterface $inventory,
        private readonly ListRequest $list,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            [$request, $page, $limit] = $this->list->from($operation, $context, filterable: ['product', 'location']);

            return ListRequest::paginator($this->inventory->levels($request), self::present(...), $page, $limit);
        }

        $level = $this->inventory->findLevelById((string) ($uriVariables['id'] ?? ''));

        return null === $level ? null : self::present($level);
    }

    public static function present(InventoryLevel $level): InventoryLevelResource
    {
        $resource = new InventoryLevelResource();
        $resource->id = $level->id()->toRfc4122();
        $resource->productId = $level->product()->id()->toRfc4122();
        $resource->sku = $level->product()->sku();
        $resource->productName = $level->product()->name();
        $resource->locationId = $level->location()->id()->toRfc4122();
        $resource->locationCode = $level->location()->code();
        $resource->locationName = $level->location()->name();
        $resource->onHand = $level->onHand();
        $resource->reserved = $level->reserved();
        $resource->available = $level->available();
        $resource->version = $level->version();
        $resource->updatedAt = $level->updatedAt();

        return $resource;
    }
}
