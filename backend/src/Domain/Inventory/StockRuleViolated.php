<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

/** A change that would break a stock invariant. Nothing was changed. */
final class StockRuleViolated extends \DomainException
{
    public const string BELOW_ZERO = 'below_zero';
    public const string BELOW_RESERVED = 'below_reserved';
    public const string NOT_POSITIVE = 'not_positive';
    public const string NO_CHANGE = 'no_change';
    public const string TOO_LARGE = 'too_large';
    public const string INSUFFICIENT_AVAILABLE = 'insufficient_available';
    public const string OVER_RELEASE = 'over_release';

    public function __construct(string $message, public readonly string $rule)
    {
        parent::__construct($message);
    }
}
