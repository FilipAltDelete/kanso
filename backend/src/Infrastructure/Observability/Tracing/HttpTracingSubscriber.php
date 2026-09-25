<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Observability\Tracing;

use Kanso\Core\Internal\Infrastructure\Observability\HttpMetricsListener;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The SERVER span of an API request, and the three moments a process has time
 * to send what it recorded.
 *
 * The span starts before routing (so it covers authentication) and continues a
 * trace the caller started, if its `traceparent` says so. It is named after
 * the *route*, never the path — `GET _api_/orders/{id}_get` is one operation
 * whatever the order, exactly as the metrics label it (HttpMetricsListener).
 *
 * Exporting happens on `kernel.terminate`, which PHP-FPM runs after the
 * response has gone back to the client, on a worker going idle, and at the end
 * of a console command. None of those is on anyone's critical path.
 */
final class HttpTracingSubscriber implements EventSubscriberInterface, ResetInterface
{
    /**
     * Held for exactly one request: under PHP-FPM the kernel handles one main
     * request per process lifetime. `reset()` forgets it for a kernel that
     * handles more (the test kernel); `Tracing::reset()` is what ends it.
     */
    private ?SpanInterface $span = null;

    public function __construct(private readonly Tracing $tracing)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Ahead of everything, the router (32) and the firewall (8) included.
            KernelEvents::REQUEST => [['onRequest', 4096], ['onRouted', 31]],
            KernelEvents::EXCEPTION => ['onException', 4096],
            KernelEvents::RESPONSE => ['onResponse', -4096],
            KernelEvents::TERMINATE => ['onTerminate', -4096],
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerStoppedEvent::class => 'flush',
            ConsoleEvents::TERMINATE => ['flush', -4096],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !$this->tracing->enabled() || HttpMetricsListener::ignored($request->getPathInfo())) {
            return;
        }

        $this->span = $this->tracing->begin(
            // Renamed after routing (onRouted); the method is what is known now.
            $request->getMethod() ?: 'HTTP',
            SpanKind::KIND_SERVER,
            [
                'http.request.method' => $request->getMethod(),
                'url.path' => $request->getPathInfo(),
                'url.scheme' => $request->getScheme(),
                'server.address' => $request->getHost(),
                'client.address' => $request->getClientIp(),
            ],
            $this->tracing->parent(self::carrierFrom($request)),
        );
    }

    public function onRouted(RequestEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');

        if (null === $this->span || !$event->isMainRequest() || !\is_string($route)) {
            return;
        }

        $this->span->updateName($event->getRequest()->getMethod().' '.$route);
        $this->span->setAttribute('http.route', $route);
    }

    public function onException(ExceptionEvent $event): void
    {
        if (null !== $this->span && $event->isMainRequest()) {
            $this->span->recordException($event->getThrowable());
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (null === $this->span || !$event->isMainRequest()) {
            return;
        }

        $status = $event->getResponse()->getStatusCode();
        $this->span->setAttribute('http.response.status_code', $status);

        // Server spans are errors on 5xx only: a 404 or a 422 is the API
        // answering correctly, and a trace backend full of red 4xx hides the
        // 500 that matters.
        if ($status >= 500) {
            $this->span->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (null !== $this->span) {
            $this->tracing->end($this->span);
            $this->span = null;
        }

        $this->flush();
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        // A busy worker exports as its batch fills; an idle one sends the rest
        // rather than sitting on them until the next message arrives.
        if ($event->isWorkerIdle()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->tracing->flush();
    }

    public function reset(): void
    {
        $this->span = null;
    }

    /** @return array<string, string> */
    private static function carrierFrom(Request $request): array
    {
        $carrier = [];

        foreach (['traceparent', 'tracestate'] as $header) {
            $value = $request->headers->get($header);

            if (null !== $value) {
                $carrier[$header] = $value;
            }
        }

        return $carrier;
    }
}
