<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Controller;

use Kanso\Core\Internal\Domain\Observability\MetricsExporterInterface;
use Kanso\Core\Internal\Domain\Observability\MetricsInterface;
use Kanso\Core\Internal\Domain\Observability\MetricSourceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What a scraper reads. Counters and histograms were written where the events
 * happened; the gauges are read here, once per scrape.
 *
 * Deliberately outside `/api`, and deliberately not routed by the reference
 * proxy (`docker/proxy/nginx.conf` forwards `/`, `/api` and `/health` only):
 * queue depth and route timings describe the installation, and a scraper
 * reaches the API container directly. A deployment that does put it behind
 * the public entry point has to restrict it there.
 */
final class MetricsController
{
    /** @param iterable<MetricSourceInterface> $sources */
    public function __construct(
        private readonly MetricsInterface $metrics,
        private readonly MetricsExporterInterface $exporter,
        private readonly iterable $sources,
    ) {
    }

    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function __invoke(): Response
    {
        foreach ($this->sources as $source) {
            $source->collect($this->metrics);
        }

        return new Response(
            $this->exporter->render(),
            Response::HTTP_OK,
            ['Content-Type' => $this->exporter->contentType()],
        );
    }
}
