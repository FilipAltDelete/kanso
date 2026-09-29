<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Security;

/**
 * Refresh tokens in families: a sign-in starts one, and every rotation
 * replaces the family's token with the next. A spent token is remembered as
 * spent until it would have expired, so a copy presented later is recognised
 * as a replay rather than as an unknown token.
 */
interface RefreshTokenStoreInterface
{
    /** A new opaque refresh token for the user, in a new family. */
    public function issue(string $userId): string;

    /**
     * The token that replaces a consumed one, in the same family. Null when
     * the family was revoked in the meantime.
     */
    public function rotate(ConsumedRefreshToken $consumed): ?string;

    /**
     * Spends a token in one atomic step, so two requests with the same token
     * cannot both use it. Null when the token is unknown or expired; a token
     * spent before comes back with `replayed` set.
     */
    public function consume(string $token): ?ConsumedRefreshToken;

    /** Ends the family's session: its live token stops working, and no rotation in flight can extend it. */
    public function revokeFamily(string $family): void;

    /** Ends the session the token belongs to (sign-out), whether the token is live or spent. */
    public function revoke(string $token): void;

    public function revokeAllFor(string $userId): void;

    /** Seconds a refresh token is valid for. */
    public function ttl(): int;
}
