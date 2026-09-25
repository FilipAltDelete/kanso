<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Observability\Tracing;

use OpenTelemetry\API\Trace\SpanKind;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * One span where a message is sent, one where it is handled, and the trace
 * context carried between them in the envelope.
 *
 * Sending: a PRODUCER span, child of whatever is current — the API request, or
 * the handler of the message that is dispatching this one — whose context goes
 * into a `TraceContextStamp`. Handling: a CONSUMER span whose parent is read
 * back from that stamp, so a message handled an hour later on another machine
 * is still a child of what sent it. Retries keep the stamp (Messenger re-sends
 * the same envelope), so every attempt hangs off the same producer.
 *
 * A message with no stamp — dispatched while tracing was off, or by a release
 * that predates it — starts a trace of its own rather than joining the
 * previous message's: `Tracing::reset()` has cleared the context by then.
 *
 * Span attributes name the message class, never its contents: order numbers,
 * addresses and customer ids do not belong in a trace backend.
 */
final class TracingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Tracing $tracing)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$this->tracing->enabled()) {
            return $stack->next()->handle($envelope, $stack);
        }

        $received = $envelope->last(ReceivedStamp::class);

        return null === $received
            ? $this->send($envelope, $stack)
            : $this->consume($envelope, $stack, $received->getTransportName());
    }

    private function send(Envelope $envelope, StackInterface $stack): Envelope
    {
        $name = self::shortName($envelope);
        $span = $this->tracing->begin('send '.$name, SpanKind::KIND_PRODUCER, [
            'messaging.operation.type' => 'send',
            'kanso.message' => $name,
        ]);

        try {
            // The span is current, so the carrier is *its* context: the
            // consumer becomes a child of the send, not a sibling of it.
            $envelope = $envelope->withoutAll(TraceContextStamp::class)
                ->with(new TraceContextStamp($this->tracing->carrier()));

            // Unrouted messages are handled right here, inside the send span,
            // which is the truth about where their time went.
            $envelope = $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $error) {
            $this->tracing->end($span, $error);

            throw $error;
        }

        $this->tracing->end($span);

        return $envelope;
    }

    private function consume(Envelope $envelope, StackInterface $stack, string $transport): Envelope
    {
        $name = self::shortName($envelope);
        $stamp = $envelope->last(TraceContextStamp::class);
        $id = $envelope->last(TransportMessageIdStamp::class)?->getId();

        $span = $this->tracing->begin(
            'process '.$name,
            SpanKind::KIND_CONSUMER,
            [
                'messaging.operation.type' => 'process',
                'messaging.destination.name' => $transport,
                'messaging.message.id' => null === $id ? null : (string) $id,
                'kanso.message' => $name,
                'kanso.retry' => $envelope->last(RedeliveryStamp::class)?->getRetryCount(),
            ],
            null !== $stamp ? $this->tracing->parent($stamp->carrier) : null,
        );

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $error) {
            $this->tracing->end($span, $error);

            throw $error;
        }

        $this->tracing->end($span);

        return $envelope;
    }

    private static function shortName(Envelope $envelope): string
    {
        $class = $envelope->getMessage()::class;
        $at = strrpos($class, '\\');

        return false === $at ? $class : substr($class, $at + 1);
    }
}
