<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Security;

/**
 * A refresh token as RefreshTokenStoreInterface::consume() found it: whose
 * it is, the family it belongs to (one family per sign-in, carried through
 * every rotation), and whether it had been spent before.
 */
final readonly class ConsumedRefreshToken
{
    public function __construct(
        public string $userId,
        public string $family,
        /** True when the token had already been used: someone is replaying a copy. */
        public bool $replayed,
    ) {
    }
}
