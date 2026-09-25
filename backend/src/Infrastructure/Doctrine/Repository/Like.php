<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

/** A search term as a LIKE pattern matches it literally: `%` and `_` are text, not wildcards. */
final class Like
{
    public static function escape(string $term): string
    {
        return addcslashes($term, '%_\\');
    }
}
