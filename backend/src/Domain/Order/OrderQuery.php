<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/** A validated order-list request; the application layer builds it from query parameters. */
final readonly class OrderQuery
{
    public const array SORTABLE = ['placedAt', 'number', 'total', 'customerName', 'status'];

    /**
     * @param list<OrderStatus>                      $statuses
     * @param list<string>                           $channels        channel codes
     * @param list<string>                           $tags            orders with any of these tags
     * @param list<PaymentStatus>                    $paymentStatuses
     * @param list<array{field: string, desc: bool}> $sort            fields from SORTABLE
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
        /** Only orders placed for this customer record (id). */
        public ?string $customerId = null,
        public array $tags = [],
        public array $paymentStatuses = [],
        /** Only orders with a shipment shipped at or after this instant. */
        public ?\DateTimeImmutable $shippedFrom = null,
        /** Only orders with a shipment shipped before this instant. */
        public ?\DateTimeImmutable $shippedBefore = null,
    ) {
    }
}
