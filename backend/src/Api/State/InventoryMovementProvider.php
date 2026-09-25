<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\InventoryMovementResource;
use Kanso\Core\Internal\Domain\Inventory\InventoryMovement;
use Kanso\Core\Internal\Domain\Inventory\InventoryStoreInterface;

/**
 * @implements ProviderInterface<InventoryMovementResource>
 */
final class InventoryMovementProvider implements ProviderInterface
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

            return ListRequest::paginator($this->inventory->movements($request), self::present(...), $page, $limit);
        }

        $movement = $this->inventory->findMovementById((string) ($uriVariables['id'] ?? ''));

        return null === $movement ? null : self::present($movement);
    }

    public static function present(InventoryMovement $movement): InventoryMovementResource
    {
        $change = $movement->change();
        $resource = new InventoryMovementResource();
        $resource->id = $movement->id()->toRfc4122();
        $resource->type = $movement->type()->value;
        $resource->reason = $movement->reason()?->value;
        $resource->note = $movement->note();
        $resource->orderId = $movement->orderId()?->toRfc4122();
        $resource->orderNumber = $movement->orderNumber();
        $resource->productId = $movement->product()->id()->toRfc4122();
        $resource->sku = $movement->product()->sku();
        $resource->productName = $movement->product()->name();
        $resource->locationId = $movement->location()->id()->toRfc4122();
        $resource->locationCode = $movement->location()->code();
        $resource->locationName = $movement->location()->name();
        $resource->onHandChange = $change->onHandDelta();
        $resource->onHandBefore = $change->onHandBefore;
        $resource->onHandAfter = $change->onHandAfter;
        $resource->reservedBefore = $change->reservedBefore;
        $resource->reservedAfter = $change->reservedAfter;
        $resource->actorId = $movement->actor()->id;
        $resource->actorName = $movement->actor()->name;
        $resource->occurredAt = $movement->occurredAt();

        return $resource;
    }
}
