<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Exception;

final class AuthenticationFailed extends ApplicationException
{
    public function status(): int
    {
        return 401;
    }

    public function title(): string
    {
        return 'Unauthorized';
    }
}
