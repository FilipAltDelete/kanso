<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Kanso\Core\Internal\Api\State\BulkTransitionProcessor;
use Kanso\Core\Internal\Api\State\ListRequest;

/**
 * The outcome of one transition applied to many orders (ADR-0015): the
 * orders that moved, and for each that did not, why.
 */
#[ApiResource(
    shortName: 'BulkTransition',
    operations: [
        new Post(
            uriTemplate: '/orders/bulk-transitions',
            status: 200,
            input: BulkTransitionInput::class,
            processor: BulkTransitionProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
            normalizationContext: ['skip_null_values' => false],
            openapi: new OpenApiOperation(
                summary: 'Move up to 500 orders through the same transition.',
                description: 'Each order is moved on its own, in its own transaction, as by `POST /api/orders/{id}/transitions`: same state machine, stock reservation and events. An order that cannot move (not allowed from its status, not enough stock, changed since the `version` you sent, not found) is listed in `failed` with the code and message it would have got alone, and the others still move. Send each order\'s `version` as you last saw it; without one, the current version is used. `ship` is refused per order: shipping goes through shipments.',
            ),
        ),
    ],
)]
final class BulkTransitionResource
{
    public string $transition = '';

    /** @var list<array{id: string, number: string, status: string, version: int}> */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'number' => ['type' => 'string'], 'status' => ['type' => 'string'], 'version' => ['type' => 'integer']]]])]
    public array $moved = [];

    /** @var list<array{id: string, number: ?string, code: string, message: string}> */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'number' => ['type' => ['string', 'null']], 'code' => ['type' => 'string'], 'message' => ['type' => 'string']]]])]
    public array $failed = [];
}
