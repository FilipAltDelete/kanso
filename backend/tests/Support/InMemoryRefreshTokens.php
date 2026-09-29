<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Support;

use Kanso\Core\Internal\Domain\Security\ConsumedRefreshToken;
use Kanso\Core\Internal\Domain\Security\RefreshTokenStoreInterface;

/** Families and replays as the Redis store keeps them, without expiry. */
final class InMemoryRefreshTokens implements RefreshTokenStoreInterface
{
    /** @var array<string, string> the live tokens' user ids, by token */
    public array $tokens = [];

    /** @var array<string, string> each live token's family */
    private array $familyOf = [];

    /** @var array<string, array{0: string, 1: string}> user id and family of each spent token */
    private array $spent = [];

    /** @var array<string, string> each family's user */
    private array $families = [];

    /** @var array<string, true> */
    public array $revokedFamilies = [];

    public function issue(string $userId): string
    {
        return $this->add($userId, bin2hex(random_bytes(8)));
    }

    public function rotate(ConsumedRefreshToken $consumed): ?string
    {
        return isset($this->revokedFamilies[$consumed->family]) ? null : $this->add($consumed->userId, $consumed->family);
    }

    public function consume(string $token): ?ConsumedRefreshToken
    {
        if (isset($this->tokens[$token])) {
            $this->spent[$token] = [$this->tokens[$token], $this->familyOf[$token]];
            unset($this->tokens[$token], $this->familyOf[$token]);

            return new ConsumedRefreshToken($this->spent[$token][0], $this->spent[$token][1], false);
        }

        return isset($this->spent[$token]) ? new ConsumedRefreshToken($this->spent[$token][0], $this->spent[$token][1], true) : null;
    }

    public function revokeFamily(string $family): void
    {
        $this->revokedFamilies[$family] = true;
        foreach (array_keys($this->familyOf, $family, true) as $token) {
            unset($this->tokens[$token], $this->familyOf[$token]);
        }
    }

    public function revoke(string $token): void
    {
        $family = $this->familyOf[$token] ?? $this->spent[$token][1] ?? null;
        if (null !== $family) {
            $this->revokeFamily($family);
        }
    }

    public function revokeAllFor(string $userId): void
    {
        foreach (array_keys($this->families, $userId, true) as $family) {
            $this->revokeFamily((string) $family);
        }
    }

    public function ttl(): int
    {
        return 3600;
    }

    private function add(string $userId, string $family): string
    {
        $token = bin2hex(random_bytes(8));
        $this->tokens[$token] = $userId;
        $this->familyOf[$token] = $family;
        $this->families[$family] = $userId;

        return $token;
    }
}
