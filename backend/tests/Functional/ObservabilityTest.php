<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Infrastructure\Observability\Tracing\Tracing;
use Kanso\Core\Tests\Support\ProbeMessage;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;

/** `/metrics` and the request span, through the real kernel (the test kernel traces into memory). */
final class ObservabilityTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheScrapeNeedsNoTokenAndServesTheGauges(): void
    {
        $body = $this->scrape();

        self::assertResponseHeaderSame('Content-Type', 'text/plain; version=0.0.4; charset=UTF-8');
        self::assertStringContainsString('kanso_build_info{version="0.1.0-dev",env="test"} 1', $body);

        // Every transport, at zero before anything was ever queued, so an
        // alert on a queue that is not draining can exist from day one.
        foreach (['async', 'ext', 'failed'] as $transport) {
            self::assertMatchesRegularExpression('/^kanso_queue_depth\{transport="'.$transport.'"\} \d+$/m', $body);
        }
    }

    public function testARequestIsCountedUnderItsRouteNotItsPath(): void
    {
        $before = $this->requestsCounted('auth_me', '401');

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);

        self::assertSame($before + 1, $this->requestsCounted('auth_me', '401'));
    }

    public function testTheProbesAndTheScrapeAreNotCounted(): void
    {
        $this->client->request('GET', '/health/live');
        $body = $this->scrape();

        self::assertStringNotContainsString('route="health_live"', $body);
        self::assertStringNotContainsString('route="metrics"', $body);
    }

    public function testARequestIsOneServerSpanNamedAfterItsRouteContinuingTheCallersTrace(): void
    {
        $this->client->request('GET', '/api/auth/me', server: [
            'HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ]);

        $servers = array_values(array_filter(
            $this->exporter()->getSpans(),
            static fn (mixed $span): bool => $span instanceof ImmutableSpan && SpanKind::KIND_SERVER === $span->getKind(),
        ));

        self::assertCount(1, $servers);
        self::assertSame('GET auth_me', $servers[0]->getName());
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $servers[0]->getTraceId());
        self::assertSame('00f067aa0ba902b7', $servers[0]->getParentSpanId());
        self::assertSame(401, $servers[0]->getAttributes()->get('http.response.status_code'));
        self::assertFalse(Span::getCurrent()->getContext()->isValid(), 'Nothing is left current once the request is over.');
    }

    /**
     * Workers are the long-running processes, so isolation has to be proved
     * there: two messages in one process, and the second must not start inside
     * the first one's trace.
     */
    public function testTheSecondMessageDoesNotJoinTheFirstOnesTrace(): void
    {
        $container = static::getContainer();
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new ProbeMessage()));
        $transport->send(new Envelope(new ProbeMessage()));

        $current = [];
        $resetter = $container->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ResetServicesListener($resetter));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(2));
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, static function () use (&$current, $container): void {
            // A span a handler opened and never ended — what the reset is for.
            $tracing = $container->get(Tracing::class);
            self::assertInstanceOf(Tracing::class, $tracing);
            $current[] = Span::getCurrent()->getContext()->isValid();
            $tracing->begin('leaked', SpanKind::KIND_INTERNAL);
        });

        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        // No handler for the probe: the worker's failure path is fine here, the
        // spans are what is being looked at.
        new Worker(['probe' => $transport], $bus, $dispatcher)->run(['sleep' => 0]);

        self::assertSame([false, false], $current, 'Each message started from the root context.');

        $consumed = array_values(array_filter(
            $this->exporter()->getSpans(),
            static fn (mixed $span): bool => $span instanceof ImmutableSpan && SpanKind::KIND_CONSUMER === $span->getKind(),
        ));
        self::assertCount(2, $consumed);
        self::assertNotSame($consumed[0]->getTraceId(), $consumed[1]->getTraceId());
    }

    private function scrape(): string
    {
        $this->client->request('GET', '/metrics');
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function requestsCounted(string $route, string $status): int
    {
        $pattern = \sprintf('/^kanso_http_request_duration_seconds_count\{route="%s",method="GET",status="%s"\} (\d+)$/m', $route, $status);

        return 1 === preg_match($pattern, $this->scrape(), $match) ? (int) $match[1] : 0;
    }

    private function exporter(): InMemoryExporter
    {
        $exporter = static::getContainer()->get('kanso.tracing.exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        return $exporter;
    }
}
