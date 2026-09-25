<?php

declare(strict_types=1);

namespace Kanso\Application\User;

use Kanso\Application\Exception\ValidationFailed;
use Kanso\Domain\Security\PasswordHasherInterface;
use Kanso\Domain\User\Role;
use Kanso\Domain\User\User;
use Kanso\Domain\User\UserStoreInterface;
use Psr\Clock\ClockInterface;

final class UserService
{
    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @param list<string> $roles */
    public function create(string $email, string $password, array $roles, ?string $name = null): User
    {
        $email = trim($email);
        $violations = [];

        if ('' === $email) {
            $violations[] = ['path' => 'email', 'message' => 'An email is required.', 'code' => 'required'];
        } elseif (null !== $this->users->findByEmail($email)) {
            $violations[] = ['path' => 'email', 'message' => 'A user with this email already exists.', 'code' => 'duplicate'];
        }

        if ('' === $password) {
            $violations[] = ['path' => 'password', 'message' => 'A password is required.', 'code' => 'required'];
        }

        foreach (array_diff($roles, Role::ALL) as $unknown) {
            $violations[] = ['path' => 'roles', 'message' => \sprintf('Unknown role "%s".', $unknown), 'code' => 'unknown_role'];
        }

        if ([] !== $violations) {
            throw new ValidationFailed($violations);
        }

        $user = new User($email, [] === $roles ? [Role::VIEWER] : array_values($roles), $name, $this->clock->now());
        $user->setPasswordHash($this->hasher->hash($password));
        $this->users->save($user);

        return $user;
    }

    public function hasAnyUser(): bool
    {
        return $this->users->count() > 0;
    }
}
