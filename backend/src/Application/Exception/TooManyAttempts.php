<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Exception;

final class TooManyAttempts extends ApplicationException
{
    public function status(): int
    {
        return 429;
    }

    public function title(): string
    {
        return 'Too Many Requests';
    }
}
