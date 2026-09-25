<?php

declare(strict_types=1);

namespace Kanso\Infrastructure\Security;

use Kanso\Domain\User\Role;
use Kanso\Domain\User\User;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The security layer's view of a user, kept out of the domain entity so `User`
 * stays a plain Doctrine entity.
 *
 * The identifier is the user id, so the access token's `sub` claim is the id,
 * not an address that can change.
 */
final class AuthenticatedUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** @param list<string> $roles */
    private function __construct(
        private readonly string $identifier,
        private readonly array $roles,
        private readonly ?string $passwordHash,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self((string) $user->id(), $user->roles(), $user->passwordHash());
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->identifier);

        return $this->identifier;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [] === $this->roles ? [Role::VIEWER] : $this->roles;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
