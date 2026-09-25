<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Observability;

use Kanso\Core\Internal\Domain\Observability\MetricsInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Request duration, recorded on `kernel.terminate` so the write to Redis
 * happens after the response has been handed back — measuring a request must
 * not lengthen it.
 *
 * The route name is the label, never the path: `/api/orders/{id}` is one
 * series whatever the order, while labelling by path would mint a new series
 * per order and turn a scrape into an order export.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class HttpMetricsListener
{
    public function __construct(private readonly MetricsInterface $metrics)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        if (self::ignored($request->getPathInfo())) {
            return;
        }

        $startedAt = $request->server->get('REQUEST_TIME_FLOAT');

        if (!is_numeric($startedAt)) {
            return;
        }

        $route = $request->attributes->get('_route');

        $this->metrics->observe('http_request_duration_seconds', [
            'route' => \is_string($route) ? $route : 'unmatched',
            'method' => $request->getMethod(),
            'status' => (string) $event->getResponse()->getStatusCode(),
        ], microtime(true) - (float) $startedAt);
    }

    /** The probes and the scrape itself: high frequency, no information. */
    public static function ignored(string $path): bool
    {
        return str_starts_with($path, '/health') || '/metrics' === $path;
    }
}
