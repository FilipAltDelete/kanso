<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Dashboard;

interface DashboardQueryInterface
{
    /**
     * Counts for the day that runs from `$dayStart` (inclusive) to `$dayEnd`
     * (exclusive), both UTC instants, and the first `$stockOutLimit` stock-outs.
     */
    public function summary(\DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd, int $stockOutLimit): DashboardSummary;
}
