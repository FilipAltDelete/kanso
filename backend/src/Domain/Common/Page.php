<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Common;

/**
 * @template T of object
 */
final readonly class Page
{
    /** @param list<T> $items */
    public function __construct(public array $items, public int $total)
    {
    }
}
