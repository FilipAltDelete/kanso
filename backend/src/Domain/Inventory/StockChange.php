<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Inventory;

/** The before and after of one change to an inventory level, as a movement records it. */
final readonly class StockChange
{
    public function __construct(
        public int $onHandBefore,
        public int $onHandAfter,
        public int $reservedBefore,
        public int $reservedAfter,
    ) {
    }

    public function onHandDelta(): int
    {
        return $this->onHandAfter - $this->onHandBefore;
    }

    public function reservedDelta(): int
    {
        return $this->reservedAfter - $this->reservedBefore;
    }
}
