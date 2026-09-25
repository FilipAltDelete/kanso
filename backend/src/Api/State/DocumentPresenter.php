<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use Kanso\Core\Internal\Api\Resource\DocumentResource;
use Kanso\Core\Internal\Application\Document\DocumentService;
use Kanso\Core\Internal\Domain\Document\Document;

final class DocumentPresenter
{
    public function __construct(private readonly DocumentService $documents)
    {
    }

    public function present(Document $document): DocumentResource
    {
        $resource = new DocumentResource();
        $resource->id = (string) $document->id();
        $resource->type = $document->type()->value;
        $resource->orderId = (string) $document->orderId();
        $resource->orderNumber = $document->orderNumber();
        $resource->shipmentId = null === $document->shipmentId() ? null : (string) $document->shipmentId();
        $resource->orderVersion = $document->orderVersion();
        $resource->locale = $document->locale();
        // Why it failed stays in the database and the logs: the message can
        // name internal hosts, which is nothing for an API client.
        $resource->status = $document->status()->value;
        $resource->filename = $document->filename();
        $resource->downloadUrl = $this->documents->downloadUrl($document);
        $resource->byteSize = $document->byteSize();
        $actor = $document->requestedBy();
        $resource->requestedBy = ['id' => $actor->id, 'name' => $actor->name];
        $resource->createdAt = $document->createdAt();
        $resource->completedAt = $document->completedAt();

        return $resource;
    }
}
