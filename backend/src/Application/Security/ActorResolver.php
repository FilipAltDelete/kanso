<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Security;

use Kanso\Core\Internal\Domain\Security\Actor;
use Kanso\Core\Internal\Domain\Security\ApiKey;
use Kanso\Core\Internal\Domain\Security\ApiKeyStoreInterface;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;

/** Turns the authenticated principal into the actor an audit trail records. */
final class ActorResolver
{
    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly ApiKeyStoreInterface $apiKeys,
    ) {
    }

    public function resolve(?string $identifier): Actor
    {
        if (null === $identifier || '' === $identifier) {
            return Actor::console();
        }

        if (str_starts_with($identifier, ApiKey::IDENTIFIER_PREFIX)) {
            $key = $this->apiKeys->findById(substr($identifier, \strlen(ApiKey::IDENTIFIER_PREFIX)));

            return new Actor($identifier, 'API key: '.($key?->name() ?? $identifier));
        }

        return new Actor($identifier, $this->users->findById($identifier)?->email() ?? $identifier);
    }
}
