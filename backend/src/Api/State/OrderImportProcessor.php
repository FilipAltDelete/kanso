<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\OrderImportResource;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Import\ImportLog;
use Kanso\Core\Internal\Application\Order\OrderImporter;
use Kanso\Core\Internal\Domain\Import\ImportRun;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProcessorInterface<null, OrderImportResource>
 */
final class OrderImportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly OrderImporter $importer,
        private readonly ImportLog $log,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OrderImportResource
    {
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);
        $actor = $this->actor->get();

        $result = $this->importer->import(
            $request->getContent(),
            \in_array(strtolower((string) $request->query->get('dryRun', '')), ['1', 'true'], true),
            $actor,
        );

        $resource = new OrderImportResource();
        $resource->dryRun = $result->dryRun;
        $resource->rows = $result->rows;
        $resource->orders = $result->orders;
        $resource->created = $result->created;
        $resource->existing = $result->existing;
        $resource->failed = $result->failed;
        $resource->newCustomers = $result->newCustomers;
        $resource->errors = $result->errors;

        if (!$result->dryRun) {
            $counts = ['rows' => $result->rows, 'orders' => $result->orders, 'created' => $result->created, 'existing' => $result->existing, 'failed' => $result->failed, 'newCustomers' => $result->newCustomers];
            $resource->importRunId = $this->log->record(ImportRun::ORDERS, $request->query->getString('filename'), $actor, $counts, $result->errors)->id()->toRfc4122();
        }

        return $resource;
    }
}
