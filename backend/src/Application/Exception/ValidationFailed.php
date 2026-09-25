<?php

declare(strict_types=1);

namespace Kanso\Application\Exception;

final class ValidationFailed extends ApplicationException
{
    /** @param list<array{path: string, message: string, code: string}> $violations */
    public function __construct(array $violations)
    {
        parent::__construct('The request is not valid.', $violations);
    }

    public function status(): int
    {
        return 422;
    }

    public function title(): string
    {
        return 'Unprocessable Entity';
    }
}
