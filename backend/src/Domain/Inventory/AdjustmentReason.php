<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

/**
 * Why stock was adjusted by hand. A fixed list for now; merchant-defined
 * reasons would be settings rows, added when a merchant needs one.
 */
enum AdjustmentReason: string
{
    case Received = 'received';
    case Count = 'count';
    case Damaged = 'damaged';
    case Lost = 'lost';
    case Found = 'found';
    case Returned = 'returned';
    case Correction = 'correction';
    case Other = 'other';

    /** "Other" says nothing by itself, so it needs a note. */
    public function requiresNote(): bool
    {
        return self::Other === $this;
    }
}
