<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\StockImportResource;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Import\ImportLog;
use Kanso\Core\Internal\Application\Inventory\StockImporter;
use Kanso\Core\Internal\Domain\Import\ImportRun;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProcessorInterface<null, StockImportResource>
 */
final class StockImportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly StockImporter $importer,
        private readonly ImportLog $log,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockImportResource
    {
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);
        $actor = $this->actor->get();

        $result = $this->importer->import($request->getContent(), \in_array(strtolower((string) $request->query->get('dryRun', '')), ['1', 'true'], true), $actor);

        $resource = new StockImportResource();
        $resource->dryRun = $result->dryRun;
        $resource->rows = $result->rows;
        $resource->changed = $result->changed;
        $resource->unchanged = $result->unchanged;
        $resource->failed = $result->failed;
        $resource->errors = $result->errors;

        if (!$result->dryRun) {
            $counts = ['rows' => $result->rows, 'changed' => $result->changed, 'unchanged' => $result->unchanged, 'failed' => $result->failed];
            $resource->importRunId = $this->log->record(ImportRun::STOCK, $request->query->getString('filename'), $actor, $counts, $result->errors)->id()->toRfc4122();
        }

        return $resource;
    }
}
