<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\CancelItemsInput;
use Kanso\Core\Internal\Api\Resource\EditOrderInput;
use Kanso\Core\Internal\Api\Resource\OrderResource;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Order\OrderEditService;

/**
 * Edits and partial cancels; both answer with the order as it now is.
 *
 * @implements ProcessorInterface<EditOrderInput|CancelItemsInput, OrderResource>
 */
final class EditOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly OrderEditService $edits,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OrderResource
    {
        $id = (string) ($uriVariables['id'] ?? '');

        $order = match (true) {
            // Only the properties the request carried are initialized, so only they are passed on.
            $data instanceof EditOrderInput => $this->edits->edit($id, get_object_vars($data), $this->actor->get()),
            $data instanceof CancelItemsInput => $this->edits->cancelUnits($id, get_object_vars($data), $this->actor->get()),
            default => throw new \LogicException(\sprintf('Unexpected input %s.', get_debug_type($data))),
        };

        return OrderPresenter::present($order, detail: true);
    }
}
