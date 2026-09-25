<?php

declare(strict_types=1);

namespace Kanso\Infrastructure\Security;

use Kanso\Domain\Security\AccessTokenIssuerInterface;
use Kanso\Domain\User\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class LexikAccessTokenIssuer implements AccessTokenIssuerInterface
{
    public function __construct(
        private readonly JWTTokenManagerInterface $tokens,
        private readonly int $ttl,
    ) {
    }

    public function issue(User $user): string
    {
        return $this->tokens->createFromPayload(AuthenticatedUser::fromUser($user), [
            // A token id, so an individual token can be denied later.
            'jti' => bin2hex(random_bytes(8)),
            'email' => $user->email(),
        ]);
    }

    public function ttl(): int
    {
        return $this->ttl;
    }
}
