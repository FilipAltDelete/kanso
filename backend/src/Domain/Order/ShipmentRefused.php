<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/** A shipment the order cannot take. Nothing was changed. */
final class ShipmentRefused extends \DomainException
{
    /** The order is not in a status that ships: not confirmed yet, on hold, or finished. */
    public const string NOT_SHIPPABLE = 'not_shippable';
    /** A line would ship more than it has left to ship. */
    public const string EXCEEDS_REMAINING = 'exceeds_remaining';
    /** A line has no stock reserved to ship: the order was confirmed before reservations existed. */
    public const string NOT_RESERVED = 'not_reserved';
    /** A shipment of nothing. */
    public const string EMPTY = 'empty_shipment';

    public function __construct(string $message, public readonly string $reason, public readonly ?int $linePosition = null)
    {
        parent::__construct($message);
    }
}
