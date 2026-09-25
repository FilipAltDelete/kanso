<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\UserResource;
use Kanso\Core\Internal\Domain\User\User;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;

/**
 * Users for the API; not the security layer's user provider
 * (Infrastructure\Security\UserProvider).
 *
 * @implements ProviderInterface<UserResource>
 */
final class UserProvider implements ProviderInterface
{
    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly ListRequest $list,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            [$request, $page, $limit] = $this->list->from($operation, $context, UserStoreInterface::SORTABLE, ['status']);

            return ListRequest::paginator($this->users->search($request), self::present(...), $page, $limit);
        }

        $user = $this->users->findById((string) ($uriVariables['id'] ?? ''));

        return null === $user ? null : self::present($user);
    }

    public static function present(User $user): UserResource
    {
        $resource = new UserResource();
        $resource->id = $user->id()->toRfc4122();
        $resource->email = $user->email();
        $resource->name = $user->name();
        $resource->role = $user->role();
        $resource->status = $user->isEnabled() ? 'active' : 'deactivated';
        $resource->createdAt = $user->createdAt();

        return $resource;
    }
}
