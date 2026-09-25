<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Observability;

use Kanso\Core\Internal\Infrastructure\Observability\Tracing\TraceLogProcessor;
use Kanso\Core\Internal\Infrastructure\Observability\Tracing\TracerProviderFactory;
use Kanso\Core\Internal\Infrastructure\Observability\Tracing\Tracing;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceLogProcessor::class)]
final class TraceLogProcessorTest extends TestCase
{
    public function testALineWrittenInsideASpanCarriesItsIds(): void
    {
        $tracing = new Tracing(TracerProviderFactory::create('', '1.0.0', 'test', new InMemoryExporter()));
        $span = $tracing->begin('GET auth_me', SpanKind::KIND_SERVER);

        try {
            $record = new TraceLogProcessor()($this->record());
        } finally {
            $tracing->reset();
        }

        self::assertSame($span->getContext()->getTraceId(), $record->extra['trace_id']);
        self::assertSame($span->getContext()->getSpanId(), $record->extra['span_id']);
    }

    public function testALineWrittenOutsideAnySpanIsLeftAlone(): void
    {
        $record = new TraceLogProcessor()($this->record());

        self::assertArrayNotHasKey('trace_id', $record->extra);
        self::assertArrayNotHasKey('span_id', $record->extra);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello');
    }
}
