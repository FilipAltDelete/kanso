<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Document;

use Kanso\Core\Internal\Application\Exception\NotFound;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Document\Document;
use Kanso\Core\Internal\Domain\Document\DocumentStatus;
use Kanso\Core\Internal\Domain\Document\DocumentStoreInterface;
use Kanso\Core\Internal\Domain\Document\DocumentType;
use Kanso\Core\Internal\Domain\Document\PdfRendererInterface;
use Kanso\Core\Internal\Domain\Document\TemplateRendererInterface;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderStoreInterface;
use Kanso\Core\Internal\Domain\Order\Shipment;
use Kanso\Core\Internal\Domain\Storage\ObjectStorageInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pick lists and packing slips. A request only queues the work: rendering
 * runs in a worker (GenerateDocument), writes the PDF to object storage, and
 * the browser downloads it from there through a short-lived signed URL.
 * Documents only read orders; they never change one.
 */
final class DocumentService
{
    /** How long a download link works. Long enough to click, short enough not to be worth leaking. */
    public const int DOWNLOAD_TTL_SECONDS = 300;

    /** A queued document older than this is presumed lost (no worker running) and not handed out again. */
    public const int PENDING_REUSE_SECONDS = 600;

    public function __construct(
        private readonly DocumentStoreInterface $documents,
        private readonly OrderStoreInterface $orders,
        private readonly TemplateRendererInterface $templates,
        private readonly PdfRendererInterface $pdf,
        private readonly ObjectStorageInterface $storage,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Queues a document for the order as it is now, or hands back the one
     * already made (or being made) for this version of the order.
     */
    public function request(string $orderId, mixed $type, mixed $locale, Actor $actor, mixed $shipmentId = null): Document
    {
        $violations = [];
        $documentType = \is_string($type) ? DocumentType::tryFrom($type) : null;
        if (null === $documentType) {
            $violations[] = ['path' => 'type', 'message' => \sprintf('The type is one of: %s.', implode(', ', array_column(DocumentType::cases(), 'value'))), 'code' => 'invalid_choice'];
        }
        $locale ??= 'en';
        if (!\in_array($locale, DocumentLabels::locales(), true)) {
            $violations[] = ['path' => 'locale', 'message' => 'The locale is "sv" or "en".', 'code' => 'invalid_choice'];
        }
        if (null !== $shipmentId && DocumentType::PackingSlip !== $documentType) {
            $violations[] = ['path' => 'shipmentId', 'message' => 'Only a packing slip can be for one shipment.', 'code' => 'invalid_choice'];
        }
        if ([] !== $violations) {
            throw new ValidationFailed($violations);
        }
        \assert($documentType instanceof DocumentType && \is_string($locale));

        $order = $this->orders->findById($orderId) ?? throw new NotFound(\sprintf('No order "%s".', $orderId));

        $shipment = null;
        if (null !== $shipmentId) {
            $shipment = $this->shipment($order, $shipmentId)
                ?? throw new ValidationFailed([['path' => 'shipmentId', 'message' => \sprintf('Order %s has no shipment "%s".', $order->number(), \is_string($shipmentId) ? $shipmentId : '?'), 'code' => 'unknown_shipment']]);
            if ($shipment->isVoided()) {
                throw new ValidationFailed([['path' => 'shipmentId', 'message' => 'That shipment was voided; nothing left in it.', 'code' => 'voided_shipment']]);
            }
        }

        $now = $this->clock->now();
        $existing = $this->documents->findReusable($documentType, $order->id(), $order->version(), $locale, $now->modify(\sprintf('-%d seconds', self::PENDING_REUSE_SECONDS)), $shipment?->id());
        if (null !== $existing) {
            return $existing;
        }

        $document = new Document(
            $documentType,
            $order->id(),
            $order->number(),
            $order->version(),
            $locale,
            $actor,
            $now,
            $shipment?->id(),
            null === $shipment ? null : OrderDocumentData::shipmentNumber($order, $shipment),
        );
        $this->documents->save($document);
        // After the row is committed, so a worker never looks for a document that is not there yet.
        $this->bus->dispatch(new GenerateDocument((string) $document->id()));

        return $document;
    }

    public function get(string $id): Document
    {
        return $this->documents->findById($id) ?? throw new NotFound(\sprintf('No document "%s".', $id));
    }

    /** A link to the PDF, while it lasts; null until the document is done. */
    public function downloadUrl(Document $document): ?string
    {
        $key = $document->storageKey();
        if (DocumentStatus::Done !== $document->status() || null === $key) {
            return null;
        }

        return $this->storage->presignDownload($key, self::DOWNLOAD_TTL_SECONDS, $document->filename());
    }

    /**
     * The worker's half. Safe to run twice: a done document is left alone, and
     * a retry overwrites the same storage key.
     */
    public function generate(string $id): void
    {
        $document = $this->documents->findById($id);
        if (null === $document) {
            throw new UnrecoverableMessageHandlingException(\sprintf('No document "%s".', $id));
        }
        if (DocumentStatus::Done === $document->status()) {
            return;
        }

        $order = $this->orders->findById((string) $document->orderId());
        if (null === $order) {
            $this->fail($document, 'The order no longer exists.');

            throw new UnrecoverableMessageHandlingException(\sprintf('Order "%s" of document "%s" is gone.', $document->orderId(), $id));
        }

        $document->start();
        $this->documents->save($document);

        $shipmentId = $document->shipmentId();
        $shipment = null === $shipmentId ? null : $this->shipment($order, (string) $shipmentId);
        if (null !== $shipmentId && null === $shipment) {
            $this->fail($document, 'The shipment no longer exists.');

            throw new UnrecoverableMessageHandlingException(\sprintf('Shipment "%s" of document "%s" is gone.', $shipmentId, $id));
        }

        $html = $this->templates->render($document->type(), OrderDocumentData::build($order, $document->locale(), $this->clock->now(), $shipment));
        $pdf = $this->pdf->render($html);

        $key = $document->intendedStorageKey();
        $this->storage->write($key, $pdf, 'application/pdf');

        $document->complete($key, \strlen($pdf), $this->clock->now());
        $this->documents->save($document);
    }

    private function shipment(Order $order, mixed $id): ?Shipment
    {
        foreach ($order->shipments() as $shipment) {
            if (\is_string($id) && (string) $shipment->id() === $id) {
                return $shipment;
            }
        }

        return null;
    }

    /** Called when the worker gives up on a document for good. */
    public function fail(Document $document, string $reason): void
    {
        $document->fail($reason, $this->clock->now());
        $this->documents->save($document);
    }
}
