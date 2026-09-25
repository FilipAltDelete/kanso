<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Observability;

use Kanso\Core\Internal\Infrastructure\Observability\MessengerMetricsSubscriber;
use Kanso\Core\Tests\Support\ProbeMessage;
use Kanso\Core\Tests\Support\RecordingMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

#[CoversClass(MessengerMetricsSubscriber::class)]
final class MessengerMetricsSubscriberTest extends TestCase
{
    private RecordingMetrics $metrics;
    private MessengerMetricsSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->metrics = new RecordingMetrics();
        $this->subscriber = new MessengerMetricsSubscriber($this->metrics);
    }

    public function testAHandledMessageIsCountedAndTimed(): void
    {
        $envelope = new Envelope(new ProbeMessage());

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        self::assertSame(
            [['transport' => 'async', 'result' => 'handled']],
            array_column($this->metrics->of('messenger_messages_total'), 'labels'),
        );
        self::assertSame(
            [['transport' => 'async', 'message' => 'ProbeMessage']],
            array_column($this->metrics->of('messenger_handler_duration_seconds'), 'labels'),
        );
    }

    public function testARetryIsNotYetAFailure(): void
    {
        $envelope = new Envelope(new ProbeMessage());
        $failure = new WorkerMessageFailedEvent($envelope, 'ext', new \RuntimeException('nope'));
        $failure->setForRetry();

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'ext'));
        $this->subscriber->onFailed($failure);

        self::assertSame('retried', $this->metrics->of('messenger_messages_total')[0]['labels']['result']);
    }

    public function testAMessageThatWillNotBeRetriedIsAFailure(): void
    {
        $envelope = new Envelope(new ProbeMessage());

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'ext'));
        $this->subscriber->onFailed(new WorkerMessageFailedEvent($envelope, 'ext', new \RuntimeException('nope')));

        self::assertSame('failed', $this->metrics->of('messenger_messages_total')[0]['labels']['result']);
    }

    public function testTheClockOfOneMessageNeverReachesTheNext(): void
    {
        $envelope = new Envelope(new ProbeMessage());

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        $this->subscriber->reset();

        // A message handled without ever being received here records the
        // count but no duration, rather than one measured from the last message.
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        self::assertCount(2, $this->metrics->of('messenger_messages_total'));
        self::assertCount(1, $this->metrics->of('messenger_handler_duration_seconds'));
    }
}
