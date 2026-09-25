<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/**
 * What the merchant knows about the order's payment. Set by hand in Phase 1,
 * later by payment integrations. Only the state: Kanso never sees card data.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
