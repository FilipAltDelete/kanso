<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Security;

use Kanso\Core\Internal\Domain\Security\ConsumedRefreshToken;
use Kanso\Core\Internal\Domain\Security\RefreshTokenStoreInterface;

/**
 * Only the hash of a refresh token is stored, so a Redis dump hands out no
 * sessions (ADR-0019).
 *
 * - `refresh_token:<hash>` holds a live token's `<user>|<family>`.
 * - `refresh_spent:<hash>` holds the same for a spent one, for as long as it
 *   would have lived, so a replayed copy is recognised.
 * - `refresh_family:<family>` holds the hash of the family's live token, or
 *   `revoked`. A family has one live token at a time: each rotation spends
 *   one and issues the next.
 * - `refresh_families:<user>` lists the user's families, so a password
 *   change can end every session.
 *
 * Spending a token and issuing its successor are each one Lua script, so two
 * requests with the same token cannot both succeed, and a rotation cannot
 * slip past a revocation.
 */
final class RedisRefreshTokenStore implements RefreshTokenStoreInterface
{
    private const string TOKEN = 'refresh_token:';
    private const string SPENT = 'refresh_spent:';
    private const string FAMILY = 'refresh_family:';
    private const string FAMILIES = 'refresh_families:';
    private const string REVOKED = 'revoked';
    private const int TTL = 30 * 24 * 3600;

    /**
     * KEYS: the token, its spent marker. The spent marker keeps what the
     * token held for the rest of the token's life.
     */
    private const string CONSUME = <<<'LUA'
        local value = redis.call('GET', KEYS[1])
        if value then
            local ttl = redis.call('PTTL', KEYS[1])
            redis.call('DEL', KEYS[1])
            if ttl > 0 then
                redis.call('SET', KEYS[2], value, 'PX', ttl)
            end
            return {1, value}
        end
        local spent = redis.call('GET', KEYS[2])
        if spent then
            return {2, spent}
        end
        return {0}
        LUA;

    /**
     * KEYS: the new token, its family, the user's families. ARGV: the
     * token's value, its hash, the lifetime, the family, and '1' when the
     * family must already exist (a rotation) rather than be new (a sign-in).
     */
    private const string ISSUE = <<<'LUA'
        local current = redis.call('GET', KEYS[2])
        if ARGV[5] == '1' and (not current or current == 'revoked') then
            return 0
        end
        redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[3])
        redis.call('SET', KEYS[2], ARGV[2], 'EX', ARGV[3])
        redis.call('SADD', KEYS[3], ARGV[4])
        redis.call('EXPIRE', KEYS[3], ARGV[3])
        return 1
        LUA;

    /** KEYS: the family. Marks it revoked and answers the hash of its live token, if any. */
    private const string REVOKE = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        redis.call('SET', KEYS[1], 'revoked', 'EX', ARGV[1])
        return current
        LUA;

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function issue(string $userId): string
    {
        $this->forgetEndedFamilies($userId);

        $token = $this->write($userId, bin2hex(random_bytes(16)), false);
        \assert(null !== $token, 'A new family cannot have been revoked.');

        return $token;
    }

    public function rotate(ConsumedRefreshToken $consumed): ?string
    {
        return $this->write($consumed->userId, $consumed->family, true);
    }

    public function consume(string $token): ?ConsumedRefreshToken
    {
        if ('' === $token) {
            return null;
        }

        $fingerprint = $this->fingerprint($token);
        $result = $this->run(self::CONSUME, [self::TOKEN.$fingerprint, self::SPENT.$fingerprint], []);
        if (!\is_array($result) || !\in_array($result[0] ?? null, [1, 2], true)) {
            return null;
        }

        $owner = self::owner($result[1] ?? null);

        return null === $owner ? null : new ConsumedRefreshToken($owner[0], $owner[1], 2 === $result[0]);
    }

    public function revokeFamily(string $family): void
    {
        $live = $this->run(self::REVOKE, [self::FAMILY.$family], [self::TTL]);

        // After the family is marked: a rotation from now on is refused, so
        // this token is the last one the family can have.
        if (\is_string($live) && self::REVOKED !== $live) {
            $this->redis->del(self::TOKEN.$live);
        }
    }

    public function revoke(string $token): void
    {
        if ('' === $token) {
            return;
        }

        $fingerprint = $this->fingerprint($token);
        $value = $this->redis->get(self::TOKEN.$fingerprint);
        if (!\is_string($value)) {
            $value = $this->redis->get(self::SPENT.$fingerprint);
        }

        $owner = self::owner($value);
        if (null !== $owner) {
            $this->revokeFamily($owner[1]);
        }
    }

    public function revokeAllFor(string $userId): void
    {
        $families = $this->families($userId);
        foreach ($families as $family) {
            $this->revokeFamily($family);
        }

        // Only these: a family started meanwhile is not ended, but stays listed.
        if ([] !== $families) {
            $this->redis->sRem(self::FAMILIES.$userId, ...$families);
        }
    }

    public function ttl(): int
    {
        return self::TTL;
    }

    private function write(string $userId, string $family, bool $rotating): ?string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $fingerprint = $this->fingerprint($token);

        $written = $this->run(
            self::ISSUE,
            [self::TOKEN.$fingerprint, self::FAMILY.$family, self::FAMILIES.$userId],
            [$userId.'|'.$family, $fingerprint, self::TTL, $family, $rotating ? '1' : '0'],
        );

        return 1 === $written ? $token : null;
    }

    /**
     * Families expire with their last token, and revoked ones are over; the
     * user's list would otherwise grow with every sign-in.
     */
    private function forgetEndedFamilies(string $userId): void
    {
        $families = $this->families($userId);
        if ([] === $families) {
            return;
        }

        $states = $this->redis->mGet(array_map(static fn (string $family): string => self::FAMILY.$family, $families));
        $ended = [];
        foreach ($families as $i => $family) {
            $state = \is_array($states) ? ($states[$i] ?? false) : false;
            if (!\is_string($state) || self::REVOKED === $state) {
                $ended[] = $family;
            }
        }

        if ([] !== $ended) {
            $this->redis->sRem(self::FAMILIES.$userId, ...$ended);
        }
    }

    /** @return list<string> */
    private function families(string $userId): array
    {
        $members = $this->redis->sMembers(self::FAMILIES.$userId);

        return \is_array($members) ? array_values(array_filter($members, 'is_string')) : [];
    }

    /**
     * @param list<string>     $keys
     * @param list<string|int> $arguments
     */
    private function run(string $script, array $keys, array $arguments): mixed
    {
        // phpredis keeps the last error until cleared; start clean, so an
        // earlier command's error is not taken for this script's.
        $this->redis->clearLastError();
        $result = $this->redis->eval($script, [...$keys, ...$arguments], \count($keys));
        $error = $this->redis->getLastError();
        if (null !== $error) {
            throw new \RuntimeException('A refresh-token script failed in Redis: '.$error);
        }

        return $result;
    }

    /** @return array{0: string, 1: string}|null the user id and the family */
    private static function owner(mixed $value): ?array
    {
        if (!\is_string($value)) {
            return null;
        }

        $parts = explode('|', $value, 2);

        return 2 === \count($parts) && '' !== $parts[0] && '' !== $parts[1] ? [$parts[0], $parts[1]] : null;
    }

    private function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }
}
