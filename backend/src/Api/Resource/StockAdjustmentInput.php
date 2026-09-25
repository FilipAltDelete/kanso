<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

/** A manual stock adjustment. Exactly one of `delta` and `onHand`. */
final class StockAdjustmentInput
{
    public string $productId = '';
    public string $locationId = '';
    /** Add (positive) or remove (negative). */
    public ?int $delta = null;
    /** Set on hand to what was counted. */
    public ?int $onHand = null;
    public string $reason = '';
    public ?string $note = null;
    /** The level's version as last read; 0 when there is no stock at the location yet. */
    public ?int $expectedVersion = null;
}
