<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\ApiKeyResource;
use Kanso\Core\Internal\Domain\Security\ApiKey;
use Kanso\Core\Internal\Domain\Security\ApiKeyStoreInterface;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * @implements ProviderInterface<ApiKeyResource>
 */
final class ApiKeyProvider implements ProviderInterface
{
    public function __construct(
        private readonly ApiKeyStoreInterface $keys,
        private readonly UserStoreInterface $users,
        private readonly ClockInterface $clock,
        private readonly ListRequest $list,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            [$request, $page, $limit] = $this->list->from($operation, $context, ApiKeyStoreInterface::SORTABLE);

            return ListRequest::paginator($this->keys->search($request), $this->present(...), $page, $limit);
        }

        $key = $this->keys->findById((string) ($uriVariables['id'] ?? ''));

        return null === $key ? null : $this->present($key);
    }

    public function present(ApiKey $key, ?string $plainKey = null): ApiKeyResource
    {
        $now = $this->clock->now();
        $creator = null === $key->createdBy() ? null : $this->users->findById($key->createdBy()->toRfc4122());

        $resource = new ApiKeyResource();
        $resource->id = $key->id()->toRfc4122();
        $resource->name = $key->name();
        $resource->role = $key->role();
        $resource->status = match (true) {
            $key->isRevoked() => 'revoked',
            $key->isExpired($now) => 'expired',
            default => 'active',
        };
        $resource->createdBy = $key->createdBy()?->toRfc4122();
        $resource->createdByName = $creator?->name() ?? $creator?->email();
        $resource->createdAt = $key->createdAt();
        $resource->expiresAt = $key->expiresAt();
        $resource->lastUsedAt = $key->lastUsedAt();
        $resource->revokedAt = $key->revokedAt();
        $resource->key = $plainKey;

        return $resource;
    }
}
