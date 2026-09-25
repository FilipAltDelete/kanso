<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Common;

/** One page of a list: what to match, how to sort, which rows. */
final readonly class PageRequest
{
    /**
     * @param array<string, string>       $filters field => value, exact match
     * @param array<string, 'asc'|'desc'> $sort    field => direction, already checked against what the list can sort by
     */
    public function __construct(
        public int $offset = 0,
        public int $limit = 50,
        public ?string $search = null,
        public array $filters = [],
        public array $sort = [],
    ) {
    }
}
