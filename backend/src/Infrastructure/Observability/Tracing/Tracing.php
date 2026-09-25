<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Observability\Tracing;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as SdkTracerProviderInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The spans this process has open, and the one place they are opened and closed.
 *
 * OpenTelemetry keeps the *current* span in a process-wide context storage,
 * which under PHP-FPM dies with the request but in a worker outlives every
 * message. A span left active by message A would make message B its child — a
 * trace claiming one order's stock push was caused by another's. So the spans
 * that are activated are pushed here, `end()` pops them in order, and
 * `reset()` (run between messages by `services_resetter`) ends whatever is
 * left and detaches every scope in the storage, ours or not.
 *
 * The provider itself — its exporter and the batch of finished spans waiting
 * to be sent — is held for the life of the process on purpose. Finished spans
 * are output, not state: nothing a later message does reads them.
 */
final class Tracing implements ResetInterface
{
    /** @var list<array{span: SpanInterface, scope: ScopeInterface}> */
    private array $active = [];

    private ?TracerInterface $tracer = null;

    public function __construct(private readonly TracerProviderInterface $provider)
    {
    }

    public function tracer(): TracerInterface
    {
        return $this->tracer ??= $this->provider->getTracer('kanso', TracerProviderFactory::class);
    }

    /** False when no endpoint is configured: every span is a no-op and nothing is propagated. */
    public function enabled(): bool
    {
        return $this->provider instanceof SdkTracerProviderInterface;
    }

    /**
     * Starts a span and makes it the current one, so that everything below it —
     * a message dispatched, another span started — is its child.
     *
     * @param non-empty-string                          $name
     * @param SpanKind::KIND_*                          $kind
     * @param array<string, string|int|float|bool|null> $attributes
     */
    public function begin(string $name, int $kind, array $attributes = [], ?ContextInterface $parent = null): SpanInterface
    {
        $builder = $this->tracer()->spanBuilder($name)->setSpanKind($kind)->setAttributes(array_filter(
            $attributes,
            static fn (mixed $value): bool => null !== $value,
        ));

        if (null !== $parent) {
            $builder->setParent($parent);
        }

        $span = $builder->startSpan();
        $this->active[] = ['span' => $span, 'scope' => $span->activate()];

        return $span;
    }

    /**
     * Ends a span `begin()` opened, recording the error if there was one. Any
     * span opened after it and never ended is ended with it, marked abandoned,
     * so the scopes come off in the order they went on.
     */
    public function end(SpanInterface $span, ?\Throwable $error = null): void
    {
        $at = null;

        foreach ($this->active as $index => $entry) {
            if ($entry['span'] === $span) {
                $at = $index;
            }
        }

        if (null === $at) {
            return;
        }

        while (\count($this->active) > $at) {
            $entry = array_pop($this->active);
            $entry['scope']->detach();

            if ($entry['span'] === $span) {
                if (null !== $error) {
                    self::fail($span, $error);
                }
            } else {
                $entry['span']->setStatus(StatusCode::STATUS_ERROR, 'abandoned: its parent ended first');
            }

            $entry['span']->end();
        }
    }

    public static function fail(SpanInterface $span, \Throwable $error): void
    {
        $span->recordException($error);
        $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
    }

    /**
     * The current trace context as W3C headers — `traceparent` and, if any, `tracestate`.
     *
     * @return array<string, string>
     */
    public function carrier(?ContextInterface $context = null): array
    {
        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier, null, $context);

        return $carrier;
    }

    /** @param array<string, string> $carrier */
    public function parent(array $carrier): ContextInterface
    {
        return TraceContextPropagator::getInstance()->extract($carrier);
    }

    /** The trace id of the current span, or null when nothing is being recorded. */
    public function traceId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() ? $context->getTraceId() : null;
    }

    /**
     * Sends whatever finished spans are waiting. Called where the process has
     * time to spare — after the response went out, when a worker goes idle,
     * when a command ends — and never where something is waiting on it.
     */
    public function flush(): void
    {
        if ($this->provider instanceof SdkTracerProviderInterface) {
            $this->provider->forceFlush();
        }
    }

    public function reset(): void
    {
        while ([] !== $this->active) {
            $entry = array_pop($this->active);
            $entry['scope']->detach();
            $entry['span']->setStatus(StatusCode::STATUS_ERROR, 'abandoned: still open when the unit of work ended');
            $entry['span']->end();
        }

        // Anything someone else activated and never detached. The storage is
        // process-wide; the next message must start from the root context.
        while (null !== ($scope = Context::storage()->scope())) {
            $scope->detach();
        }
    }
}
