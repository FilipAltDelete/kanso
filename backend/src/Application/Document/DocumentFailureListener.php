<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Document;

use Kanso\Core\Internal\Domain\Document\DocumentStatus;
use Kanso\Core\Internal\Domain\Document\DocumentStoreInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * When the worker gives up on a document (retries spent, or a failure that
 * retrying cannot fix), the document says so, and the browser waiting on it
 * stops waiting. The message itself goes to the failure transport as usual.
 */
#[AsEventListener]
final class DocumentFailureListener
{
    public function __construct(
        private readonly DocumentStoreInterface $documents,
        private readonly DocumentService $service,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof GenerateDocument || $event->willRetry()) {
            return;
        }

        $document = $this->documents->findById($message->documentId);
        if (null === $document || DocumentStatus::Done === $document->status() || DocumentStatus::Failed === $document->status()) {
            return;
        }

        $this->service->fail($document, $event->getThrowable()->getMessage());
    }
}
