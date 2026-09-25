<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Observability;

use Kanso\Core\Internal\Domain\Observability\MetricsInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What the worker did, per transport: how many messages, and how long each took.
 *
 * It holds the start time of the message being handled, which is exactly the
 * kind of per-message state a worker resets between messages — hence
 * `ResetInterface`. A message that fails hard enough to leave the start time
 * set would otherwise hand its clock to the next one.
 */
final class MessengerMetricsSubscriber implements EventSubscriberInterface, ResetInterface
{
    private ?float $startedAt = null;

    public function __construct(private readonly MetricsInterface $metrics)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onReceived', 1024],
            WorkerMessageHandledEvent::class => 'onHandled',
            WorkerMessageFailedEvent::class => 'onFailed',
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->startedAt = microtime(true);
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->record($event->getReceiverName(), $event->getEnvelope()->getMessage(), 'handled');
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        // A message that will be retried is not a failure yet; counting it as
        // one would make three retries look like three broken syncs.
        $this->record(
            $event->getReceiverName(),
            $event->getEnvelope()->getMessage(),
            $event->willRetry() ? 'retried' : 'failed',
        );
    }

    public function reset(): void
    {
        $this->startedAt = null;
    }

    private function record(string $transport, object $message, string $result): void
    {
        $this->metrics->increment('messenger_messages_total', ['transport' => $transport, 'result' => $result]);

        if (null !== $this->startedAt) {
            $class = new \ReflectionClass($message);
            $this->metrics->observe(
                'messenger_handler_duration_seconds',
                ['transport' => $transport, 'message' => $class->getShortName()],
                microtime(true) - $this->startedAt,
            );
        }

        $this->startedAt = null;
    }
}
