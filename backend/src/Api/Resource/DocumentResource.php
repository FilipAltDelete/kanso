<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use Kanso\Core\Internal\Api\State\DocumentProvider;
use Kanso\Core\Internal\Api\State\RequestDocumentProcessor;

/**
 * A generated PDF for an order: a pick list or a packing slip. Asking for one
 * queues it; poll it until `status` is `done`, then open `downloadUrl`, a
 * signed link straight to object storage that works for five minutes. Read
 * the document again for a fresh link. Printing changes nothing, so anyone
 * signed in may.
 */
#[ApiResource(
    shortName: 'Document',
    operations: [
        new Post(
            uriTemplate: '/orders/{orderId}/documents',
            uriVariables: ['orderId'],
            status: 202,
            input: DocumentRequestInput::class,
            read: false,
            processor: RequestDocumentProcessor::class,
            description: 'Queue a pick list or packing slip for the order as it is now. An unchanged order gets back the document it already has.',
        ),
        new Get(uriTemplate: '/documents/{id}', provider: DocumentProvider::class),
    ],
    security: "is_granted('ROLE_VIEWER')",
    normalizationContext: ['skip_null_values' => false],
)]
final class DocumentResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    #[ApiProperty(identifier: false, schema: ['type' => 'string', 'enum' => ['pick_list', 'packing_slip']])]
    public string $type = '';

    #[ApiProperty(identifier: false, schema: ['type' => 'string', 'format' => 'uuid'])]
    public string $orderId = '';

    public string $orderNumber = '';

    /** Set when the document is a packing slip for one shipment. */
    public ?string $shipmentId = null;

    /** The order version the document shows. */
    public int $orderVersion = 0;

    public string $locale = '';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['queued', 'running', 'done', 'failed']])]
    public string $status = '';

    /** What the browser saves the PDF as. */
    public string $filename = '';

    /** Set once `status` is `done`; valid for five minutes from this response. */
    public ?string $downloadUrl = null;

    public ?int $byteSize = null;

    /** @var array{id: string, name: string} */
    #[ApiProperty(schema: ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string']]])]
    public array $requestedBy = ['id' => '', 'name' => ''];

    public ?\DateTimeImmutable $createdAt = null;

    public ?\DateTimeImmutable $completedAt = null;
}
