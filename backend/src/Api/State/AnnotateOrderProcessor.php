<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\BulkTagChangeInput;
use Kanso\Core\Internal\Api\Resource\NoteInput;
use Kanso\Core\Internal\Api\Resource\OrderResource;
use Kanso\Core\Internal\Api\Resource\PaymentStatusInput;
use Kanso\Core\Internal\Api\Resource\TagChangeInput;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Order\OrderAnnotationService;

/**
 * Notes, tags and payment status. A change to one order answers with the
 * order as it now is; a bulk tag change answers 204.
 *
 * @implements ProcessorInterface<NoteInput|TagChangeInput|PaymentStatusInput, OrderResource|null>
 */
final class AnnotateOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly OrderAnnotationService $annotations,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?OrderResource
    {
        $actor = $this->actor->get();
        $id = (string) ($uriVariables['id'] ?? '');

        if ($data instanceof BulkTagChangeInput) {
            $this->annotations->bulkChangeTags($data->orders, $data->add, $data->remove, $actor);

            return null;
        }

        $order = match (true) {
            $data instanceof TagChangeInput => $this->annotations->changeTags($id, $data->add, $data->remove, $actor),
            $data instanceof NoteInput => $this->annotations->addNote($id, $data->note, $actor),
            $data instanceof PaymentStatusInput => $this->annotations->changePaymentStatus($id, $data->paymentStatus, $data->version, $actor),
            default => throw new \LogicException(\sprintf('Unexpected input %s.', get_debug_type($data))),
        };

        return OrderPresenter::present($order, detail: true);
    }
}
