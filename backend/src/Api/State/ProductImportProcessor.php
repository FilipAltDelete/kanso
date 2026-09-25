<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\ProductImportResource;
use Kanso\Core\Internal\Application\Catalog\ProductImporter;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProcessorInterface<null, ProductImportResource>
 */
final class ProductImportProcessor implements ProcessorInterface
{
    public function __construct(private readonly ProductImporter $importer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductImportResource
    {
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);

        $result = $this->importer->import($request->getContent(), \in_array(strtolower((string) $request->query->get('dryRun', '')), ['1', 'true'], true));

        $resource = new ProductImportResource();
        $resource->dryRun = $result->dryRun;
        $resource->rows = $result->rows;
        $resource->created = $result->created;
        $resource->updated = $result->updated;
        $resource->unchanged = $result->unchanged;
        $resource->failed = $result->failed;
        $resource->errors = $result->errors;

        return $resource;
    }
}
