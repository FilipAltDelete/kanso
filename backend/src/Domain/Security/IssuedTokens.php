<?php

declare(strict_types=1);

namespace Kanso\Domain\Security;

/** What a successful login or refresh hands back: a short access token and a long refresh token. */
final readonly class IssuedTokens
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public string $refreshToken,
        public int $refreshExpiresIn,
    ) {
    }
}
