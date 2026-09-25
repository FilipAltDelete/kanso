<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Kanso\Core\Internal\Api\State\BulkDocumentProcessor;
use Kanso\Core\Internal\Api\State\ListRequest;

/**
 * One PDF of pick lists or packing slips for many orders (ADR-0007,
 * amended): the document queued for the orders that can be printed, and
 * for each that cannot, why.
 */
#[ApiResource(
    shortName: 'BulkDocument',
    operations: [
        new Post(
            uriTemplate: '/orders/bulk-documents',
            status: 200,
            input: BulkDocumentInput::class,
            processor: BulkDocumentProcessor::class,
            security: "is_granted('ROLE_VIEWER')",
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
            normalizationContext: ['skip_null_values' => false],
            openapi: new OpenApiOperation(
                summary: 'Queue one PDF of pick lists or packing slips for up to 100 orders.',
                description: 'Each order is printed on its own pages, in order-number order, as it is when the worker renders it. Orders that cannot be printed are left out and listed in `skipped`: `not_found`, `cancelled`, and for pick lists `on_hold` and `nothing_to_pick` (shipped or delivered). Poll `GET /api/documents/{id}` for the document as for one order\'s. The same request for the same unchanged orders gets back the document it already has. When every order is skipped, `document` is null.',
            ),
        ),
    ],
)]
final class BulkDocumentResource
{
    /** The document itself, not a link to it: its id is what to poll. */
    #[ApiProperty(readableLink: true)]
    public ?DocumentResource $document = null;

    /** @var list<array{id: string, number: ?string, code: string, message: string}> */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'number' => ['type' => ['string', 'null']], 'code' => ['type' => 'string'], 'message' => ['type' => 'string']]]])]
    public array $skipped = [];
}
