<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** A valid API key that has used up its window; answered with 429, not 401. */
final class ApiKeyRateLimited extends AuthenticationException
{
    public function __construct(
        public readonly int $retryAfter,
        public readonly int $limit,
    ) {
        parent::__construct('This API key has made too many requests.');
    }

    public function getMessageKey(): string
    {
        return 'This API key has made too many requests.';
    }
}
