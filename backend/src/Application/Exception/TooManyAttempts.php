<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Exception;

/** A rate limit reached; the answer says when to try again, as the API key limit's does. */
final class TooManyAttempts extends ApplicationException
{
    public function __construct(string $detail, public readonly int $retryAfter)
    {
        parent::__construct($detail);
    }

    public function status(): int
    {
        return 429;
    }

    public function title(): string
    {
        return 'Too Many Requests';
    }

    public function headers(): array
    {
        return ['Retry-After' => (string) max(1, $this->retryAfter)];
    }
}
