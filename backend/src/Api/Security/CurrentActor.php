<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Security;

use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Security\ApiKey;
use Kanso\Core\Internal\Domain\Security\ApiKeyStoreInterface;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** The signed-in user or API key, as the audit trail records it. */
final class CurrentActor
{
    public function __construct(
        private readonly Security $security,
        private readonly UserStoreInterface $users,
        private readonly ApiKeyStoreInterface $apiKeys,
    ) {
    }

    public function get(): Actor
    {
        $identifier = $this->security->getUser()?->getUserIdentifier()
            ?? throw new AccessDeniedException('A change needs a signed-in user or an API key.');

        if (str_starts_with($identifier, ApiKey::IDENTIFIER_PREFIX)) {
            $key = $this->apiKeys->findById(substr($identifier, \strlen(ApiKey::IDENTIFIER_PREFIX)));

            return new Actor($identifier, $key?->name() ?? $identifier);
        }

        $user = $this->users->findById($identifier);

        return new Actor($identifier, $user?->name() ?? $user?->email() ?? $identifier);
    }
}
