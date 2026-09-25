<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Security;

use Kanso\Core\Internal\Domain\Security\RefreshTokenStoreInterface;

/**
 * Only the hash of a refresh token is stored, so a Redis dump hands out no
 * sessions. Consuming a token deletes it — rotation is what makes a replayed
 * token fail.
 */
final class RedisRefreshTokenStore implements RefreshTokenStoreInterface
{
    private const string PREFIX = 'refresh:';
    private const string USER_PREFIX = 'refresh_user:';
    private const int TTL = 30 * 24 * 3600;

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function issue(string $userId): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $fingerprint = $this->fingerprint($token);

        $this->redis->setex(self::PREFIX.$fingerprint, self::TTL, $userId);
        // A per-user index, so a password change can end every session.
        $this->redis->sAdd(self::USER_PREFIX.$userId, $fingerprint);
        $this->redis->expire(self::USER_PREFIX.$userId, self::TTL);

        return $token;
    }

    public function consume(string $token): ?string
    {
        if ('' === $token) {
            return null;
        }

        $key = self::PREFIX.$this->fingerprint($token);
        $userId = $this->redis->get($key);
        if (!\is_string($userId) || '' === $userId) {
            return null;
        }

        // Rotation: the presented token is spent whether or not the caller
        // succeeds in using the new one.
        $this->redis->del($key);
        $this->redis->sRem(self::USER_PREFIX.$userId, $this->fingerprint($token));

        return $userId;
    }

    public function revoke(string $token): void
    {
        if ('' === $token) {
            return;
        }

        $fingerprint = $this->fingerprint($token);
        $userId = $this->redis->get(self::PREFIX.$fingerprint);
        $this->redis->del(self::PREFIX.$fingerprint);

        if (\is_string($userId) && '' !== $userId) {
            $this->redis->sRem(self::USER_PREFIX.$userId, $fingerprint);
        }
    }

    public function revokeAllFor(string $userId): void
    {
        $members = $this->redis->sMembers(self::USER_PREFIX.$userId);
        foreach (\is_array($members) ? $members : [] as $fingerprint) {
            $this->redis->del(self::PREFIX.$fingerprint);
        }

        $this->redis->del(self::USER_PREFIX.$userId);
    }

    public function ttl(): int
    {
        return self::TTL;
    }

    private function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }
}
