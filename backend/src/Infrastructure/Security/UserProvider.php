<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Security;

use Kanso\Core\Internal\Domain\User\UserStoreInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Access tokens carry the user id and people sign in with an email, so the
 * provider accepts both.
 *
 * @implements UserProviderInterface<AuthenticatedUser>
 */
final class UserProvider implements UserProviderInterface
{
    public function __construct(private readonly UserStoreInterface $users)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = Uuid::isValid($identifier)
            ? $this->users->findById($identifier)
            : $this->users->findByEmail($identifier);

        if (null === $user || !$user->isEnabled()) {
            throw new UserNotFoundException(\sprintf('No enabled user "%s".', $identifier));
        }

        return AuthenticatedUser::fromUser($user);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AuthenticatedUser) {
            throw new UnsupportedUserException(\sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return AuthenticatedUser::class === $class;
    }
}
