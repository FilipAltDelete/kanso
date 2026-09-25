<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

/** What caused a movement. */
enum MovementType: string
{
    /** A person changed on hand by hand, with a reason. */
    case Adjustment = 'adjustment';
    /** An order was confirmed: stock held for it, no longer available. */
    case Reservation = 'reservation';
    /** An order holding stock was cancelled: the stock is available again. */
    case Release = 'release';
    /** An order was shipped: its reserved stock left on hand. */
    case Shipment = 'shipment';
}
