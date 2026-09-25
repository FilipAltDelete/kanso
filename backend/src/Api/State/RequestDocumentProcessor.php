<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\DocumentRequestInput;
use Kanso\Core\Internal\Api\Resource\DocumentResource;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Document\DocumentService;

/**
 * @implements ProcessorInterface<DocumentRequestInput, DocumentResource>
 */
final class RequestDocumentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentPresenter $presenter,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DocumentResource
    {
        \assert($data instanceof DocumentRequestInput);

        return $this->presenter->present($this->documents->request(
            (string) ($uriVariables['orderId'] ?? ''),
            $data->type,
            $data->locale,
            $this->actor->get(),
        ));
    }
}
