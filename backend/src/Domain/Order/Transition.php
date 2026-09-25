<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/** The named moves of the order state machine (OrderStateMachine). */
enum Transition: string
{
    case Confirm = 'confirm';
    case Allocate = 'allocate';
    case StartPicking = 'start_picking';
    case Pack = 'pack';
    case Ship = 'ship';
    case Deliver = 'deliver';
    case Cancel = 'cancel';
    case Hold = 'hold';
    case Release = 'release';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $transition): string => $transition->value, self::cases());
    }
}
