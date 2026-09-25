<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Observability\Tracing;

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The tracer provider, built from one environment variable (Pimsen ADR-026).
 *
 * `OTEL_EXPORTER_OTLP_ENDPOINT` is the only thing an installation configures:
 * empty means tracing is off and every span is a no-op, set means OTLP over
 * HTTP/protobuf to `<endpoint>/v1/traces`. Everything the SDK would otherwise
 * read from its own `OTEL_*` variables — service name, resource attributes,
 * sampler, protocol — is decided here, because a knob that differs between
 * installations is a knob somebody has to keep in step across every customer.
 * The SDK is never autoloaded (`OTEL_PHP_AUTOLOAD_ENABLED` stays unset), so
 * none of those variables is read.
 *
 * Which installation this is, is deliberately absent: the process does not
 * know it, and the collector adds it to traces exactly as the scrape adds
 * `installation` to the metrics (observability/README.md).
 *
 * Sampling is `parentbased(always_on)`: a trace a caller started is kept or
 * dropped as the caller decided, and everything else is kept. Which traces are
 * worth storing is the collector's call — tail sampling there sees the whole
 * trace, a head sampler here cannot know yet whether the sync will fail.
 */
final class TracerProviderFactory
{
    /** Long enough for a collector on the same network, short enough not to stall a worker when it is gone. */
    private const float EXPORT_TIMEOUT_SECONDS = 2.0;

    public static function create(
        string $endpoint,
        string $version,
        string $environment,
        ?SpanExporterInterface $exporter = null,
    ): TracerProviderInterface {
        $endpoint = trim($endpoint);

        if (null === $exporter && '' === $endpoint) {
            return new NoopTracerProvider();
        }

        $processor = null !== $exporter
            // Tests hand in an in-memory exporter and read what it got at once.
            ? new SimpleSpanProcessor($exporter)
            : new BatchSpanProcessor(
                self::otlp($endpoint),
                Clock::getDefault(),
                exportTimeoutMillis: (int) (self::EXPORT_TIMEOUT_SECONDS * 1000),
            );

        return new TracerProvider(
            $processor,
            new ParentBased(new AlwaysOnSampler()),
            ResourceInfo::create(Attributes::create([
                'service.name' => self::serviceName(),
                'service.namespace' => 'kanso',
                'service.version' => $version,
                'service.instance.id' => gethostname() ?: 'unknown',
                'deployment.environment.name' => $environment,
            ])),
        );
    }

    /**
     * One service per process role, because that is how a trace backend draws
     * the arrow from the API to the worker. The image is the same for both;
     * what it was started as is not.
     */
    public static function serviceName(): string
    {
        if ('cli' !== \PHP_SAPI) {
            return 'kanso-api';
        }

        $argv = $_SERVER['argv'] ?? [];

        return \is_array($argv) && \in_array('messenger:consume', $argv, true) ? 'kanso-worker' : 'kanso-console';
    }

    private static function otlp(string $endpoint): SpanExporterInterface
    {
        $psr17 = new Psr17Factory();

        // No retries: a span batch is worth less than the request or message
        // that would wait for it. A collector that is down costs one timeout
        // per flush, never a failed request.
        $factory = new PsrTransportFactory(
            new Psr18Client(HttpClient::create(['timeout' => self::EXPORT_TIMEOUT_SECONDS]), $psr17, $psr17),
            $psr17,
            $psr17,
        );
        $transport = $factory->create(rtrim($endpoint, '/').'/v1/traces', ContentTypes::PROTOBUF, timeout: self::EXPORT_TIMEOUT_SECONDS, maxRetries: 0);

        return new SpanExporter($transport);
    }
}
