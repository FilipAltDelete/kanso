<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Kanso\Core\Internal\Api\State\InventoryLevelProvider;

/**
 * Stock of one product at one location. Read-only: stock changes through
 * `POST /api/stock-adjustments` (and, later, reservations and shipments), so
 * every change has a movement.
 */
#[ApiResource(
    shortName: 'InventoryLevel',
    operations: [
        new GetCollection(
            uriTemplate: '/inventory-levels',
            provider: InventoryLevelProvider::class,
            parameters: [
                'product' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], description: 'Only this product (id).'),
                'location' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], description: 'Only this location (id).'),
            ],
        ),
        new Get(uriTemplate: '/inventory-levels/{id}', provider: InventoryLevelProvider::class),
    ],
    security: "is_granted('ROLE_VIEWER')",
    // Every field, every time: a null says "not set", an absent key would say nothing.
    normalizationContext: ['skip_null_values' => false],
)]
final class InventoryLevelResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
    public string $productId = '';
    public string $sku = '';
    public string $productName = '';
    public string $locationId = '';
    public string $locationCode = '';
    public string $locationName = '';
    public int $onHand = 0;
    public int $reserved = 0;
    /** on hand − reserved; never negative. */
    public int $available = 0;
    /** Send it as `expectedVersion` when adjusting. */
    public int $version = 0;
    public ?\DateTimeImmutable $updatedAt = null;
}
