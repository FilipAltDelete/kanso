<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\DocumentResource;
use Kanso\Core\Internal\Application\Document\DocumentService;
use Kanso\Core\Internal\Application\Exception\NotFound;

/**
 * @implements ProviderInterface<DocumentResource>
 */
final class DocumentProvider implements ProviderInterface
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentPresenter $presenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?DocumentResource
    {
        try {
            return $this->presenter->present($this->documents->get((string) ($uriVariables['id'] ?? '')));
        } catch (NotFound) {
            return null;
        }
    }
}
