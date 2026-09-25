<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

/** What caused a movement. Reservations and shipments add their types when orders arrive. */
enum MovementType: string
{
    case Adjustment = 'adjustment';
}
