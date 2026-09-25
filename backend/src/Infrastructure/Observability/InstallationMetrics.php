<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Observability;

use Doctrine\DBAL\Connection;
use Kanso\Core\Internal\Domain\Observability\MetricsInterface;
use Kanso\Core\Internal\Domain\Observability\MetricSourceInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The gauges, read at scrape time.
 *
 * Queue depth is a `COUNT(*)` because the queue is a MySQL table and not a
 * broker with its own statistics — the same count an operator would run by
 * hand to decide whether one worker is still enough.
 */
final class InstallationMetrics implements MetricSourceInterface
{
    /** @param ServiceProviderInterface<object> $transports the configured transports, named — never instantiated, only listed */
    public function __construct(
        private readonly Connection $connection,
        private readonly ServiceProviderInterface $transports,
        private readonly string $version,
        private readonly string $environment,
    ) {
    }

    public function collect(MetricsInterface $metrics): void
    {
        $metrics->set('build_info', ['version' => $this->version, 'env' => $this->environment], 1.0);

        $this->queueDepth($metrics);
    }

    private function queueDepth(MetricsInterface $metrics): void
    {
        // Every configured transport, at zero until something is waiting in it:
        // "the queue is not draining" has to be expressible before the first
        // message, which means the series has to exist before it.
        $depths = array_fill_keys(array_keys($this->transports->getProvidedServices()), 0);

        try {
            /** @var array<string, int|string> $rows */
            $rows = $this->connection->fetchAllKeyValue(
                'SELECT queue_name, COUNT(*) FROM messenger_messages WHERE delivered_at IS NULL GROUP BY queue_name',
            );
        } catch (\Throwable) {
            // A database the migrations have not reached yet: report nothing
            // rather than zeros that would claim the queues are empty.
            return;
        }

        foreach ($rows as $transport => $depth) {
            $depths[$transport] = (int) $depth;
        }

        foreach ($depths as $transport => $depth) {
            $metrics->set('queue_depth', ['transport' => (string) $transport], (float) $depth);
        }
    }
}
