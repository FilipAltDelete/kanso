<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Observability\Tracing;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The W3C trace context of whatever dispatched a message, carried in the
 * envelope so the worker that consumes it continues the same trace: a channel
 * sync is one trace from the request or schedule that started it through every
 * message it dispatched.
 *
 * Only ever added while tracing is on. A release that predates this class
 * cannot unserialize an envelope carrying it, so turning tracing on is a
 * deployment step taken once every process runs a release that knows it
 * (observability/README.md), never in the same change as the upgrade.
 */
final readonly class TraceContextStamp implements StampInterface
{
    /** @param array<string, string> $carrier `traceparent`, and `tracestate` when there is one */
    public function __construct(public array $carrier)
    {
    }
}
