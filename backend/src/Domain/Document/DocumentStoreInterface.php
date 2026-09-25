<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Document;

use Symfony\Component\Uid\Uuid;

interface DocumentStoreInterface
{
    public function findById(string $id): ?Document;

    /**
     * The newest document for this exact order version that is done, or
     * queued or running since `$pendingSince`: the one to hand back instead
     * of rendering again. A failed one, or one queued long ago and lost, is
     * not.
     */
    public function findReusable(DocumentType $type, Uuid $orderId, int $orderVersion, string $locale, \DateTimeImmutable $pendingSince, ?Uuid $shipmentId = null): ?Document;

    public function save(Document $document): void;
}
