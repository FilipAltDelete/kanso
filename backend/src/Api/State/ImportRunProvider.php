<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\ImportRunResource;
use Kanso\Core\Internal\Domain\Import\ImportRun;
use Kanso\Core\Internal\Domain\Import\ImportRunStoreInterface;

/**
 * @implements ProviderInterface<ImportRunResource>
 */
final class ImportRunProvider implements ProviderInterface
{
    public function __construct(
        private readonly ImportRunStoreInterface $runs,
        private readonly ListRequest $list,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            [$request, $page, $limit] = $this->list->from($operation, $context, filterable: ['type']);

            return ListRequest::paginator($this->runs->search($request), self::present(...), $page, $limit);
        }

        $run = $this->runs->findById((string) ($uriVariables['id'] ?? ''));

        return null === $run ? null : self::present($run);
    }

    public static function present(ImportRun $run): ImportRunResource
    {
        $resource = new ImportRunResource();
        $resource->id = $run->id()->toRfc4122();
        $resource->type = $run->type();
        $resource->filename = $run->filename();
        $resource->actorId = $run->actor()->id;
        $resource->actorName = $run->actor()->name;
        $resource->counts = $run->counts();
        $resource->errorCount = $run->errorCount();
        $resource->errors = $run->errors();
        $resource->startedAt = $run->startedAt();

        return $resource;
    }
}
