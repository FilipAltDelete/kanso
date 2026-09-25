<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

final class TransitionNotAllowed extends \DomainException
{
    public function __construct(
        public readonly OrderStatus $from,
        public readonly Transition $transition,
    ) {
        parent::__construct(\sprintf('An order that is %s cannot %s.', str_replace('_', ' ', $from->value), str_replace('_', ' ', $transition->value)));
    }
}
