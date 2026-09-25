<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Support;

use Kanso\Core\Internal\Domain\Observability\MetricsInterface;

/** Remembers what was recorded, so a test can assert on the series rather than on Redis. */
final class RecordingMetrics implements MetricsInterface
{
    /** @var list<array{kind: string, metric: string, labels: array<string, string>, value: float}> */
    public array $recorded = [];

    public function increment(string $metric, array $labels = [], float $by = 1.0): void
    {
        $this->recorded[] = ['kind' => 'increment', 'metric' => $metric, 'labels' => $labels, 'value' => $by];
    }

    public function observe(string $metric, array $labels, float $seconds): void
    {
        $this->recorded[] = ['kind' => 'observe', 'metric' => $metric, 'labels' => $labels, 'value' => $seconds];
    }

    public function set(string $metric, array $labels, float $value): void
    {
        $this->recorded[] = ['kind' => 'set', 'metric' => $metric, 'labels' => $labels, 'value' => $value];
    }

    /** @return list<array{kind: string, metric: string, labels: array<string, string>, value: float}> */
    public function of(string $metric): array
    {
        return array_values(array_filter($this->recorded, static fn (array $row): bool => $metric === $row['metric']));
    }
}
