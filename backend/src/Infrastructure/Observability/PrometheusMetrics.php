<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Observability;

use Kanso\Core\Internal\Domain\Observability\MetricsExporterInterface;
use Kanso\Core\Internal\Domain\Observability\MetricsInterface;
use Prometheus\CollectorRegistry;
use Prometheus\Exception\StorageException;
use Prometheus\MetricFamilySamples;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis as RedisStorage;

/**
 * The metric store, and the one place every series is declared (as in Pimsen,
 * ADR-026).
 *
 * Counters live in Redis because PHP processes are shared-nothing: an API
 * process handles one request and forgets it, so a counter kept in memory would
 * be gone before anything could scrape it, and the next request would land on
 * a different process anyway. Metric keys are the one thing in Redis that is
 * allowed to be lost — a scrape that misses is a gap in a graph.
 *
 * A request counter is the histogram's `_count`; a separate counter would say
 * the same thing twice.
 */
final class PrometheusMetrics implements MetricsInterface, MetricsExporterInterface
{
    /** Every series is prefixed with it, so `kanso_` is what a fleet dashboard filters on. */
    public const string PREFIX = 'kanso';

    /** @var array<string, array{help: string, labels: list<string>}> */
    public const array COUNTERS = [
        'messenger_messages_total' => [
            'help' => 'Messages a worker finished, by transport and outcome.',
            'labels' => ['transport', 'result'],
        ],
    ];

    /** @var array<string, array{help: string, labels: list<string>, buckets: list<float>}> */
    public const array HISTOGRAMS = [
        'http_request_duration_seconds' => [
            'help' => 'Wall time of an API request, by route and status.',
            'labels' => ['route', 'method', 'status'],
            // The 500 ms p95 budget for list views (CLAUDE.md) is a bucket
            // boundary, so "within budget" is read exactly, not interpolated.
            'buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0],
        ],
        'messenger_handler_duration_seconds' => [
            'help' => 'Wall time of one handled message, by transport and message class.',
            'labels' => ['transport', 'message'],
            // Channel syncs and stock pushes are sized in seconds, so the
            // buckets start where an HTTP histogram ends.
            'buckets' => [0.05, 0.1, 0.5, 1.0, 5.0, 10.0, 30.0, 60.0, 300.0],
        ],
    ];

    /**
     * Gauges describe the *process* answering the scrape and are set by a
     * `MetricSourceInterface` just before it renders, so they never go to
     * Redis. Every API replica reads the same Redis, so a release label stored
     * there would outlive the release — after a rollout the old version would
     * still read 1 forever — and a transport that was removed would keep its
     * last queue depth. A gauge that has to survive between scrapes would need
     * its own store; none does yet.
     *
     * @var array<string, array{help: string, labels: list<string>}>
     */
    public const array GAUGES = [
        'queue_depth' => [
            'help' => 'Messages waiting to be consumed, by transport. The queue is a MySQL table.',
            'labels' => ['transport'],
        ],
        'build_info' => [
            'help' => 'Always 1; the labels carry the release and the environment.',
            'labels' => ['version', 'env'],
        ],
    ];

    /**
     * Held for the life of the process. It is a declaration table and a Redis
     * handle, never per-message state, so a worker may keep it across messages.
     */
    private ?CollectorRegistry $registry = null;

    /**
     * The process-local gauges. Rebuilt for every scrape (see `render()`), so
     * a long-lived process never serves a value an earlier scrape set.
     */
    private ?CollectorRegistry $process = null;

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function increment(string $metric, array $labels = [], float $by = 1.0): void
    {
        $declared = self::COUNTERS[$metric] ?? throw $this->undeclared($metric);
        $values = $this->labelValues($metric, $declared['labels'], $labels);

        $this->bestEffort(function () use ($metric, $declared, $values, $by): void {
            $this->registry()
                ->getOrRegisterCounter(self::PREFIX, $metric, $declared['help'], $declared['labels'])
                ->incBy($by, $values);
        });
    }

    public function observe(string $metric, array $labels, float $seconds): void
    {
        $declared = self::HISTOGRAMS[$metric] ?? throw $this->undeclared($metric);
        $values = $this->labelValues($metric, $declared['labels'], $labels);

        $this->bestEffort(function () use ($metric, $declared, $values, $seconds): void {
            $this->registry()
                ->getOrRegisterHistogram(self::PREFIX, $metric, $declared['help'], $declared['labels'], $declared['buckets'])
                ->observe($seconds, $values);
        });
    }

    public function set(string $metric, array $labels, float $value): void
    {
        $declared = self::GAUGES[$metric] ?? throw $this->undeclared($metric);
        $values = $this->labelValues($metric, $declared['labels'], $labels);

        $this->bestEffort(function () use ($metric, $declared, $values, $value): void {
            $this->process()
                ->getOrRegisterGauge(self::PREFIX, $metric, $declared['help'], $declared['labels'])
                ->set($value, $values);
        });
    }

    public function render(): string
    {
        $local = array_map(static fn (string $gauge): string => self::PREFIX.'_'.$gauge, array_keys(self::GAUGES));

        $shared = [];

        try {
            // Minus anything an earlier release did store in Redis under a
            // gauge's name — a family may appear only once.
            $shared = array_filter(
                $this->registry()->getMetricFamilySamples(),
                static fn (MetricFamilySamples $family): bool => !\in_array($family->getName(), $local, true),
            );
        } catch (\RedisException|StorageException) {
            // Still answer with what this process knows: a scrape that shows
            // the release and nothing else says "Redis", not "down".
        }

        $format = new RenderTextFormat();
        $rendered = $format->render([...array_values($shared), ...$this->process()->getMetricFamilySamples()]);
        $this->process = null;

        return $rendered;
    }

    public function contentType(): string
    {
        return RenderTextFormat::MIME_TYPE;
    }

    /**
     * The library takes label values positionally, so the caller's map is put
     * into the order the metric was declared in. A label nobody passed is a bug
     * in the calling code and says so — the alternative is a series that is
     * silently never written.
     *
     * @param list<string>          $declared
     * @param array<string, string> $labels
     *
     * @return list<string>
     */
    private function labelValues(string $metric, array $declared, array $labels): array
    {
        return array_map(
            static function (string $label) use ($metric, $labels): string {
                if (!\array_key_exists($label, $labels)) {
                    throw new \InvalidArgumentException(\sprintf('Metric "%s" declares the label "%s"; it was not passed.', $metric, $label));
                }

                return $labels[$label];
            },
            $declared,
        );
    }

    private function undeclared(string $metric): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('No metric "%s" is declared in %s.', $metric, self::class));
    }

    private function bestEffort(callable $record): void
    {
        try {
            $record();
        } catch (\RedisException|StorageException) {
            // Redis being unreachable is already a readiness failure; it must
            // not also turn a served request into a 500.
        }
    }

    private function process(): CollectorRegistry
    {
        return $this->process ??= new CollectorRegistry(new InMemory(), registerDefaultMetrics: false);
    }

    private function registry(): CollectorRegistry
    {
        return $this->registry ??= new CollectorRegistry(
            RedisStorage::fromExistingConnection($this->redis),
            // No `php_info`: the default metrics describe the process that
            // happened to answer the scrape, which under PHP-FPM is noise.
            registerDefaultMetrics: false,
        );
    }
}
