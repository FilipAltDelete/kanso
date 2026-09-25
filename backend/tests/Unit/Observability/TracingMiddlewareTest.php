<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Observability;

use Kanso\Core\Internal\Infrastructure\Observability\Tracing\TraceContextStamp;
use Kanso\Core\Internal\Infrastructure\Observability\Tracing\TracerProviderFactory;
use Kanso\Core\Internal\Infrastructure\Observability\Tracing\Tracing;
use Kanso\Core\Internal\Infrastructure\Observability\Tracing\TracingMiddleware;
use Kanso\Core\Tests\Support\ProbeMessage;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

#[CoversClass(TracingMiddleware::class)]
#[CoversClass(Tracing::class)]
#[CoversClass(TracerProviderFactory::class)]
final class TracingMiddlewareTest extends TestCase
{
    private InMemoryExporter $exporter;
    private Tracing $tracing;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracing = new Tracing(TracerProviderFactory::create('', '1.0.0', 'test', $this->exporter));
    }

    protected function tearDown(): void
    {
        $this->tracing->reset();
    }

    public function testSendingStampsTheEnvelopeWithTheSendSpansContext(): void
    {
        $sent = $this->middleware()->handle(new Envelope(new ProbeMessage()), new StackMiddleware());

        $stamp = $sent->last(TraceContextStamp::class);
        self::assertNotNull($stamp);

        $send = $this->span('send ProbeMessage');
        self::assertSame(SpanKind::KIND_PRODUCER, $send->getKind());
        self::assertSame(
            \sprintf('00-%s-%s-01', $send->getTraceId(), $send->getSpanId()),
            $stamp->carrier['traceparent'],
            'The consumer will be a child of the send, not a sibling of it.',
        );
    }

    public function testConsumingContinuesTheTraceTheStampCarries(): void
    {
        $carrier = ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'];
        $envelope = new Envelope(new ProbeMessage(), [new TraceContextStamp($carrier), new ReceivedStamp('async'), new RedeliveryStamp(2)]);

        $this->middleware()->handle($envelope, new StackMiddleware());

        $process = $this->span('process ProbeMessage');
        self::assertSame(SpanKind::KIND_CONSUMER, $process->getKind());
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $process->getTraceId());
        self::assertSame('00f067aa0ba902b7', $process->getParentSpanId());
        self::assertSame('async', $process->getAttributes()->get('messaging.destination.name'));
        self::assertSame(2, $process->getAttributes()->get('kanso.retry'));
    }

    public function testTheSpanNamesTheMessageButCarriesNoneOfItsContents(): void
    {
        $this->middleware()->handle(new Envelope(new ProbeMessage('customer@example.com')), new StackMiddleware());

        $attributes = $this->span('send ProbeMessage')->getAttributes()->toArray();

        self::assertSame('ProbeMessage', $attributes['kanso.message']);
        self::assertNotContains('customer@example.com', $attributes);
    }

    public function testAFailingHandlerMarksItsSpanAndLeavesNothingCurrent(): void
    {
        $envelope = new Envelope(new ProbeMessage(), [new ReceivedStamp('async')]);
        $failing = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new \RuntimeException('the channel timed out');
            }
        };

        try {
            $this->middleware()->handle($envelope, new StackMiddleware($failing));
            self::fail('The handler\'s exception reaches the worker, which decides about the retry.');
        } catch (\RuntimeException $error) {
            self::assertSame('the channel timed out', $error->getMessage());
        }

        $process = $this->span('process ProbeMessage');
        self::assertSame(StatusCode::STATUS_ERROR, $process->getStatus()->getCode());
        self::assertSame('exception', $process->getEvents()[0]->getName());
        self::assertFalse(Span::getCurrent()->getContext()->isValid(), 'The next message does not start inside this one.');
    }

    public function testTheStampSurvivesTheTransportsSerializer(): void
    {
        $serializer = new PhpSerializer();
        $sent = $this->middleware()->handle(new Envelope(new ProbeMessage()), new StackMiddleware());

        $decoded = $serializer->decode($serializer->encode($sent));

        self::assertSame($sent->last(TraceContextStamp::class)?->carrier, $decoded->last(TraceContextStamp::class)?->carrier);
    }

    public function testWithTracingOffNothingIsStampedOrRecorded(): void
    {
        $off = new Tracing(TracerProviderFactory::create('', '1.0.0', 'test'));

        $sent = new TracingMiddleware($off)->handle(new Envelope(new ProbeMessage()), new StackMiddleware());

        self::assertFalse($off->enabled());
        self::assertNull(
            $sent->last(TraceContextStamp::class),
            'An envelope a pre-tracing worker cannot decode is only ever sent once someone turned tracing on.',
        );
    }

    public function testResetEndsWhatAMessageLeftOpenAndClearsTheCurrentSpan(): void
    {
        $leaked = $this->tracing->begin('leaked', SpanKind::KIND_INTERNAL);
        self::assertTrue(Span::getCurrent()->getContext()->isValid());

        $this->tracing->reset();

        self::assertFalse(Span::getCurrent()->getContext()->isValid(), 'The next message starts from the root context.');
        self::assertFalse($leaked->isRecording(), 'The leaked span was ended, not left dangling.');
        self::assertSame(StatusCode::STATUS_ERROR, $this->span('leaked')->getStatus()->getCode());
    }

    private function middleware(): TracingMiddleware
    {
        return new TracingMiddleware($this->tracing);
    }

    private function span(string $name): ImmutableSpan
    {
        foreach ($this->exporter->getSpans() as $span) {
            if ($span instanceof ImmutableSpan && $span->getName() === $name) {
                return $span;
            }
        }

        self::fail(\sprintf('No "%s" span was recorded.', $name));
    }
}
