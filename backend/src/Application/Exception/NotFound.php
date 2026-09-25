<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Exception;

final class NotFound extends ApplicationException
{
    public function status(): int
    {
        return 404;
    }

    public function title(): string
    {
        return 'Not Found';
    }
}
