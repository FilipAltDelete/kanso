<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\CreateOrderInput;
use Kanso\Core\Internal\Api\Resource\OrderResource;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Order\OrderService;

/**
 * @implements ProcessorInterface<CreateOrderInput, OrderResource>
 */
final class CreateOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OrderResource
    {
        \assert($data instanceof CreateOrderInput);

        $order = $this->orders->create(get_object_vars($data), $this->actor->get());

        return OrderPresenter::present($order, detail: true);
    }
}
