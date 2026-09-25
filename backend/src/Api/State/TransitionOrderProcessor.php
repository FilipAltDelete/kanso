<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\OrderResource;
use Kanso\Core\Internal\Api\Resource\TransitionInput;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Order\OrderService;

/**
 * @implements ProcessorInterface<TransitionInput, OrderResource>
 */
final class TransitionOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OrderResource
    {
        \assert($data instanceof TransitionInput);

        $order = $this->orders->transition(
            (string) ($uriVariables['id'] ?? ''),
            $data->transition,
            $data->version,
            $this->actor->get(),
        );

        return OrderPresenter::present($order, detail: true);
    }
}
