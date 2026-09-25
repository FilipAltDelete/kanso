<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/** A validated order-list request; the application layer builds it from query parameters. */
final readonly class OrderQuery
{
    public const array SORTABLE = ['placedAt', 'number', 'total', 'customerName', 'status'];

    /**
     * @param list<OrderStatus>                      $statuses
     * @param list<string>                           $channels channel codes
     * @param list<array{field: string, desc: bool}> $sort     fields from SORTABLE
     */
    public function __construct(
        public array $statuses = [],
        public array $channels = [],
        public ?\DateTimeImmutable $placedFrom = null,
        public ?\DateTimeImmutable $placedBefore = null,
        public string $search = '',
        public array $sort = [['field' => 'placedAt', 'desc' => true]],
        public int $offset = 0,
        public int $limit = 50,
    ) {
    }
}
