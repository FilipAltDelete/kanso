<?php

declare(strict_types=1);

namespace Kanso\Domain\Security;

interface RefreshTokenStoreInterface
{
    /** Returns a new opaque refresh token for the user. */
    public function issue(string $userId): string;

    /** Spends a token (rotation) and returns its user id, or null when it is unknown or expired. */
    public function consume(string $token): ?string;

    public function revoke(string $token): void;

    public function revokeAllFor(string $userId): void;

    /** Seconds a refresh token is valid for. */
    public function ttl(): int;
}
