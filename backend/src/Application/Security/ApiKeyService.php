<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Security;

use Kanso\Core\Internal\Application\Exception\AuthenticationFailed;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Security\ApiKey;
use Kanso\Core\Internal\Domain\Security\ApiKeyStoreInterface;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * Creating and revoking API keys. The plain key leaves this service exactly
 * once, from create(); only its hash is stored.
 */
final class ApiKeyService
{
    public function __construct(
        private readonly ApiKeyStoreInterface $keys,
        private readonly UserStoreInterface $users,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string|null $createdBy the creating user's id or email; null when created from the console
     *
     * @return array{key: ApiKey, plainKey: string}
     */
    public function create(string $name, string $role, ?\DateTimeImmutable $expiresAt = null, ?string $createdBy = null): array
    {
        $name = trim($name);
        $now = $this->clock->now();
        $violations = [];

        if ('' === $name) {
            $violations[] = ['path' => 'name', 'message' => 'An API key needs a name, so it can be recognised later.', 'code' => 'required'];
        }

        if (Role::ADMIN === $role) {
            // An integration never needs to manage users or keys; a leaked
            // admin key would hand out the whole installation.
            $violations[] = ['path' => 'role', 'message' => 'An API key cannot have the admin role.', 'code' => 'forbidden_role'];
        } elseif (!\in_array($role, Role::ALL, true)) {
            $violations[] = ['path' => 'role', 'message' => \sprintf('Unknown role "%s".', $role), 'code' => 'unknown_role'];
        }

        if (null !== $expiresAt && $expiresAt <= $now) {
            $violations[] = ['path' => 'expiresAt', 'message' => 'The expiry must be in the future.', 'code' => 'in_past'];
        }

        $creator = null;
        if (null !== $createdBy) {
            $creator = $this->users->findById($createdBy) ?? $this->users->findByEmail($createdBy);
            if (null === $creator) {
                $violations[] = ['path' => 'createdBy', 'message' => \sprintf('No user "%s".', $createdBy), 'code' => 'unknown_user'];
            }
        }

        if ([] !== $violations) {
            throw new ValidationFailed($violations);
        }

        $plainKey = ApiKey::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = new ApiKey($name, ApiKey::hash($plainKey), $role, $creator?->id(), $now, $expiresAt);
        $this->keys->save($key);

        return ['key' => $key, 'plainKey' => $plainKey];
    }

    public function revoke(string $id): ApiKey
    {
        $key = $this->keys->findById($id);
        if (null === $key) {
            throw new ValidationFailed([['path' => 'id', 'message' => \sprintf('No API key "%s".', $id), 'code' => 'unknown_api_key']]);
        }

        $key->revoke($this->clock->now());
        $this->keys->save($key);

        return $key;
    }

    /** @return array{email: null, name: string} what GET /api/auth/me shows for a key */
    public function describe(string $identifier): array
    {
        $key = str_starts_with($identifier, ApiKey::IDENTIFIER_PREFIX)
            ? $this->keys->findById(substr($identifier, \strlen(ApiKey::IDENTIFIER_PREFIX)))
            : null;

        if (null === $key) {
            throw new AuthenticationFailed('The API key no longer exists.');
        }

        return ['email' => null, 'name' => $key->name()];
    }
}
