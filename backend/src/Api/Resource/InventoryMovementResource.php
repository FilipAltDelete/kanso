<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Kanso\Core\Internal\Api\State\InventoryMovementProvider;
use Kanso\Core\Internal\Api\State\ListRequest;
use Kanso\Core\Internal\Api\State\StockAdjustmentProcessor;

/**
 * One change to one inventory level, with the level before and after. The
 * history is append-only; a stock adjustment is how a person adds to it.
 */
#[ApiResource(
    shortName: 'InventoryMovement',
    operations: [
        new GetCollection(
            uriTemplate: '/inventory-movements',
            provider: InventoryMovementProvider::class,
            parameters: [
                'product' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], description: 'Only this product (id).'),
                'location' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], description: 'Only this location (id).'),
            ],
        ),
        new Get(uriTemplate: '/inventory-movements/{id}', provider: InventoryMovementProvider::class),
        new Post(
            uriTemplate: '/stock-adjustments',
            status: 201,
            input: StockAdjustmentInput::class,
            processor: StockAdjustmentProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
            openapi: new OpenApiOperation(
                summary: 'Adjust stock by hand.',
                description: 'Send either `delta` (add or remove) or `onHand` (set to what was counted), a `reason`, and `expectedVersion`: the level\'s `version` as last read, or 0 when the product has no stock at that location yet. A different current version is a 409 and nothing changes. The response is the movement that was recorded.',
            ),
        ),
    ],
    security: "is_granted('ROLE_VIEWER')",
    // Every field, every time: a null says "not set", an absent key would say nothing.
    normalizationContext: ['skip_null_values' => false],
)]
final class InventoryMovementResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
    /** What caused it: `adjustment`, `reservation`, `release` or `shipment`. */
    public string $type = '';
    /** For adjustments: received, count, damaged, lost, found, returned, correction or other. */
    public ?string $reason = null;
    public ?string $note = null;
    /** The order behind a reservation, release or shipment. */
    public ?string $orderId = null;
    public ?string $orderNumber = null;
    public string $productId = '';
    public string $sku = '';
    public string $productName = '';
    public string $locationId = '';
    public string $locationCode = '';
    public string $locationName = '';
    /** onHandAfter − onHandBefore. */
    public int $onHandChange = 0;
    public int $onHandBefore = 0;
    public int $onHandAfter = 0;
    public int $reservedBefore = 0;
    public int $reservedAfter = 0;
    /** A user id, or `api-key:<id>`. */
    public string $actorId = '';
    /** The user's name or email, or the key's name, when the change was made. */
    public string $actorName = '';
    public ?\DateTimeImmutable $occurredAt = null;
}
