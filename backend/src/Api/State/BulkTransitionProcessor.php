<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\BulkTransitionInput;
use Kanso\Core\Internal\Api\Resource\BulkTransitionResource;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Order\BulkTransitions;
use Kanso\Core\Internal\Domain\Order\Order;

/**
 * @implements ProcessorInterface<BulkTransitionInput, BulkTransitionResource>
 */
final class BulkTransitionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly BulkTransitions $bulk,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BulkTransitionResource
    {
        \assert($data instanceof BulkTransitionInput);

        $result = $this->bulk->apply($data->orders, $data->transition, $this->actor->get());

        $resource = new BulkTransitionResource();
        $resource->transition = $result->transition;
        $resource->moved = array_map(static fn (Order $order): array => [
            'id' => (string) $order->id(),
            'number' => $order->number(),
            'status' => $order->status()->value,
            'version' => $order->version(),
        ], $result->moved);
        $resource->failed = $result->failed;

        return $resource;
    }
}
