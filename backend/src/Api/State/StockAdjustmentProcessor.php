<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\InventoryMovementResource;
use Kanso\Core\Internal\Api\Resource\StockAdjustmentInput;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Inventory\InventoryService;

/**
 * @implements ProcessorInterface<StockAdjustmentInput, InventoryMovementResource>
 */
final class StockAdjustmentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InventoryMovementResource
    {
        \assert($data instanceof StockAdjustmentInput);

        if (null === $data->expectedVersion) {
            throw new ValidationFailed([['path' => 'expectedVersion', 'message' => 'Send the version of the stock you looked at (0 when there was none), so a change made in between is not overwritten.', 'code' => 'required']]);
        }

        return InventoryMovementProvider::present($this->inventory->adjust(
            $data->productId,
            $data->locationId,
            $data->delta,
            $data->onHand,
            $data->reason,
            $data->note,
            $data->expectedVersion,
            $this->actor->get(),
        ));
    }
}
