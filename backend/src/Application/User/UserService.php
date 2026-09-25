<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\User;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Exception\NotFound;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Order\OrderInput;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Security\PasswordHasherInterface;
use Kanso\Core\Internal\Domain\Security\RefreshTokenStoreInterface;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Internal\Domain\User\User;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * Users, as an admin manages them. There is always at least one active
 * admin: demoting or deactivating the last one is refused, with the active
 * admins locked while it is checked. A deactivated user's sessions end at
 * once: access tokens are checked against the user on every request, and
 * refresh tokens are revoked.
 */
final class UserService
{
    /** The `app_user` columns' lengths. */
    private const int EMAIL_MAX = 180;
    private const int NAME_MAX = 128;

    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly ClockInterface $clock,
        private readonly TransactionInterface $transaction,
        private readonly RefreshTokenStoreInterface $refreshTokens,
    ) {
    }

    /**
     * For the console: a password of any length, and several roles.
     *
     * @param list<string> $roles
     */
    public function create(string $email, string $password, array $roles, ?string $name = null): User
    {
        $email = trim($email);
        $violations = [];

        if ('' === $email) {
            $violations[] = ['path' => 'email', 'message' => 'An email is required.', 'code' => 'required'];
        } elseif (null !== $this->users->findByEmail($email)) {
            $violations[] = self::duplicateEmail();
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

    /**
     * An admin adds someone: `email`, `name` (optional), `role` and a first
     * password, which the admin passes on. There is no email invitation.
     *
     * @param array<string, mixed> $input a request body, taken as sent
     */
    public function createFromRequest(array $input): User
    {
        $check = new OrderInput();
        $email = $this->email($check, $input['email'] ?? null);
        $name = $check->text($input['name'] ?? null, 'name', self::NAME_MAX, false);
        $role = self::role($check, $input['role'] ?? null);
        $password = PasswordPolicy::check($check, $input['password'] ?? null, 'password');
        $check->throwIfInvalid();
        \assert(null !== $email && null !== $role && null !== $password);

        $user = new User($email, [$role], $name, $this->clock->now());
        $user->setPasswordHash($this->hasher->hash($password));
        $this->users->save($user);

        return $user;
    }

    /**
     * A merge patch of `email`, `name` and `role`: what is left out stays.
     *
     * @param array<string, mixed> $input a request body, taken as sent
     */
    public function update(string $id, array $input): User
    {
        $user = $this->get($id);
        $check = new OrderInput();
        $email = \array_key_exists('email', $input) ? $this->email($check, $input['email'], $user) : $user->email();
        $name = \array_key_exists('name', $input) ? $check->text($input['name'], 'name', self::NAME_MAX, false) : $user->name();
        $role = \array_key_exists('role', $input) ? self::role($check, $input['role']) : $user->role();
        $check->throwIfInvalid();
        \assert(null !== $email && null !== $role);

        return $this->transaction->run(function () use ($user, $email, $name, $role): User {
            if (Role::ADMIN !== $role) {
                $this->keepAnAdmin($user, 'role', 'The last active admin cannot lose the admin role. Make someone else an admin first.');
            }
            $user->changeDetails($email, $name);
            $user->changeRole($role);
            $this->users->save($user);

            return $user;
        });
    }

    /**
     * Stops the user signing in, and ends their sessions. Deactivating a
     * deactivated user changes nothing. Nobody deactivates themselves.
     *
     * @param string $actorId the signed-in admin's id
     */
    public function deactivate(string $id, string $actorId): User
    {
        $user = $this->get($id);
        if ((string) $user->id() === $actorId) {
            throw new Conflict('You cannot deactivate yourself.', [['path' => '', 'message' => 'You cannot deactivate yourself.', 'code' => 'self']]);
        }

        $this->transaction->run(function () use ($user): void {
            $this->keepAnAdmin($user, '', 'The last active admin cannot be deactivated. Make someone else an admin first.');
            $user->disable();
            $this->users->save($user);
        });
        // After the commit: a refresh in between is refused anyway, since it
        // checks that the user is enabled.
        $this->refreshTokens->revokeAllFor((string) $user->id());

        return $user;
    }

    public function activate(string $id): User
    {
        $user = $this->get($id);
        $user->enable();
        $this->users->save($user);

        return $user;
    }

    /**
     * An admin sets someone's password, for a forgotten one; their sessions
     * end, so whoever had them signs in with the new one.
     */
    public function setPassword(string $id, mixed $password): User
    {
        $user = $this->get($id);
        $check = new OrderInput();
        $password = PasswordPolicy::check($check, $password, 'password');
        $check->throwIfInvalid();
        \assert(null !== $password);

        $user->setPasswordHash($this->hasher->hash($password));
        $this->users->save($user);
        $this->refreshTokens->revokeAllFor((string) $user->id());

        return $user;
    }

    public function hasAnyUser(): bool
    {
        return $this->users->count() > 0;
    }

    private function get(string $id): User
    {
        return $this->users->findById($id) ?? throw new NotFound('No such user.');
    }

    /** Refuses a change that would take away the last active admin; call inside the transaction. */
    private function keepAnAdmin(User $user, string $path, string $message): void
    {
        if (!$user->isEnabled() || !$user->isAdmin()) {
            return;
        }

        $others = array_filter($this->users->lockActiveAdmins(), static fn (User $admin): bool => !$admin->id()->equals($user->id()));
        if ([] === $others) {
            throw new Conflict($message, [['path' => $path, 'message' => $message, 'code' => 'last_admin']]);
        }
    }

    private function email(OrderInput $check, mixed $value, ?User $self = null): ?string
    {
        $email = $check->text($value, 'email', self::EMAIL_MAX);
        if (null === $email) {
            return null;
        }
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL, \FILTER_FLAG_EMAIL_UNICODE)) {
            $check->violate('email', 'This is not a valid email address.', 'invalid_email');

            return null;
        }

        $existing = $this->users->findByEmail($email);
        if (null !== $existing && (null === $self || !$existing->id()->equals($self->id()))) {
            $duplicate = self::duplicateEmail();
            $check->violate($duplicate['path'], $duplicate['message'], $duplicate['code']);

            return null;
        }

        return $email;
    }

    private static function role(OrderInput $check, mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            $check->violate('role', 'A role is required.', 'required');

            return null;
        }
        if (!\is_string($value) || !\in_array($value, Role::ALL, true)) {
            $check->violate('role', \sprintf('The role must be one of %s.', implode(', ', Role::ALL)), 'unknown_role');

            return null;
        }

        return $value;
    }

    /** @return array{path: string, message: string, code: string} */
    private static function duplicateEmail(): array
    {
        return ['path' => 'email', 'message' => 'A user with this email already exists.', 'code' => 'duplicate'];
    }
}
