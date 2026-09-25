<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\BulkDocumentInput;
use Kanso\Core\Internal\Api\Resource\BulkDocumentResource;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Document\DocumentService;

/**
 * @implements ProcessorInterface<BulkDocumentInput, BulkDocumentResource>
 */
final class BulkDocumentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentPresenter $presenter,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BulkDocumentResource
    {
        \assert($data instanceof BulkDocumentInput);
        ['document' => $document, 'skipped' => $skipped] = $this->documents->requestBatch($data->type, $data->locale, $data->orderIds, $this->actor->get());

        $result = new BulkDocumentResource();
        $result->document = null === $document ? null : $this->presenter->present($document);
        $result->skipped = $skipped;

        return $result;
    }
}
