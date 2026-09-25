<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Observability\Tracing;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

/**
 * `trace_id` and `span_id` on every log line written while a span is current,
 * so a log line leads to its trace and a trace to its log lines. Both are the
 * W3C hex forms a trace backend searches by. Nothing is added while tracing is
 * off: the current span is then the invalid one.
 */
#[AsMonologProcessor]
final class TraceLogProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = Span::getCurrent()->getContext();

        if (!$context->isValid()) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            'trace_id' => $context->getTraceId(),
            'span_id' => $context->getSpanId(),
        ]);
    }
}
