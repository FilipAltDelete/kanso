<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Exception;

final class Conflict extends ApplicationException
{
    public function status(): int
    {
        return 409;
    }

    public function title(): string
    {
        return 'Conflict';
    }
}
