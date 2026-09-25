<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Observability;

/**
 * A number that is cheaper to read at scrape time than to maintain as it
 * changes — queue depth, the release serving. Counters and histograms are
 * written where the event happens; these are gauges, and a gauge is only ever
 * as true as its last write.
 */
interface MetricSourceInterface
{
    public function collect(MetricsInterface $metrics): void;
}
