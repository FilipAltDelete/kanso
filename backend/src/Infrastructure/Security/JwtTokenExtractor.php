<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Security;

use Kanso\Core\Internal\Domain\Security\ApiKey;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * An API key and a JWT can both arrive as `Authorization: Bearer …`, and
 * Symfony runs every authenticator whose supports() is true — so without this,
 * the JWT authenticator would run after a successful API-key authentication
 * and reject the request. Hiding API-key requests from the JWT extractor makes
 * the two authenticators mutually exclusive (same as Pimsen).
 */
final class JwtTokenExtractor implements TokenExtractorInterface
{
    public function __construct(private readonly TokenExtractorInterface $inner)
    {
    }

    public function extract(Request $request): string|false
    {
        if (null !== ApiKeyAuthenticator::extract($request)) {
            return false;
        }

        $token = $this->inner->extract($request);

        return \is_string($token) && str_starts_with($token, ApiKey::PREFIX) ? false : $token;
    }
}
