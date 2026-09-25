<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Support;

use Kanso\Core\Internal\Domain\Security\RefreshTokenStoreInterface;

final class InMemoryRefreshTokens implements RefreshTokenStoreInterface
{
    /** @var array<string, string> user id by token */
    public array $tokens = [];

    public function issue(string $userId): string
    {
        $token = bin2hex(random_bytes(8));
        $this->tokens[$token] = $userId;

        return $token;
    }

    public function consume(string $token): ?string
    {
        $userId = $this->tokens[$token] ?? null;
        unset($this->tokens[$token]);

        return $userId;
    }

    public function revoke(string $token): void
    {
        unset($this->tokens[$token]);
    }

    public function revokeAllFor(string $userId): void
    {
        $this->tokens = array_filter($this->tokens, static fn (string $id): bool => $id !== $userId);
    }

    public function ttl(): int
    {
        return 3600;
    }
}
