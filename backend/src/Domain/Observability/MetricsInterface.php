<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Observability;

/**
 * What the rest of the application records. Names are declared once, by the
 * implementation, so a caller cannot invent a metric — a typo is an exception
 * here rather than a series nothing ever queries.
 *
 * Recording never fails a request: the store is best-effort (a scrape that
 * misses is a gap in a graph, an exception is a 500), while an unknown metric
 * or a missing label is a programming error and throws.
 */
interface MetricsInterface
{
    /** @param array<string, string> $labels every label the metric declares */
    public function increment(string $metric, array $labels = [], float $by = 1.0): void;

    /** @param array<string, string> $labels every label the metric declares */
    public function observe(string $metric, array $labels, float $seconds): void;

    /** @param array<string, string> $labels every label the metric declares */
    public function set(string $metric, array $labels, float $value): void;
}
