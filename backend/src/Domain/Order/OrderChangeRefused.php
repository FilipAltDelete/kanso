<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/** An edit or a partial cancel the order cannot take. Nothing was changed. */
final class OrderChangeRefused extends \DomainException
{
    /** Picking has started, part of it has shipped, or it is finished: too late to edit. */
    public const string NOT_EDITABLE = 'not_editable';
    /** Shipped, delivered or already cancelled: nothing left to cancel. */
    public const string NOT_CANCELLABLE = 'not_cancellable';
    /** A line would cancel more units than it has left to ship. */
    public const string EXCEEDS_REMAINING = 'exceeds_remaining';
    /** A line's quantity below what has shipped or been cancelled. */
    public const string BELOW_DONE = 'below_shipped_or_cancelled';
    /** The edit would leave no unit to ship; cancelling the order is a cancel. */
    public const string NOTHING_LEFT = 'nothing_left';
    /** The last units of a partly shipped order on hold: release it first, then it can finish as shipped. */
    public const string RELEASE_FIRST = 'release_first';

    public function __construct(string $message, public readonly string $reason, public readonly ?int $linePosition = null)
    {
        parent::__construct($message);
    }
}
