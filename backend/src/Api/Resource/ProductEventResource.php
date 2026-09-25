<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Kanso\Core\Internal\Api\State\ProductEventProvider;

/**
 * One entry in a product's history (ADR-0013): who created or changed it,
 * when, through what, and its fields before and after. Read-only; written
 * with the change it records.
 */
#[ApiResource(
    shortName: 'ProductEvent',
    operations: [
        new GetCollection(
            uriTemplate: '/product-events',
            provider: ProductEventProvider::class,
            parameters: [
                'product' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], required: true, description: 'The product (id) whose history to list, newest first.'),
            ],
        ),
        new Get(uriTemplate: '/product-events/{id}', provider: ProductEventProvider::class),
    ],
    security: "is_granted('ROLE_VIEWER')",
    // Every field, every time: a null says "not set", an absent key would say nothing.
    normalizationContext: ['skip_null_values' => false],
)]
final class ProductEventResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
    public string $productId = '';
    /** `created` or `updated`. */
    public string $type = '';
    /** `api` (including the web UI) or `import` (a CSV import). */
    public string $source = '';
    /** A user id, `api-key:<id>`, or `system`. */
    public string $actorId = '';
    /** The user's name or email, or the key's name, when the change was made. */
    public string $actorName = '';

    /** @var array<string, string|int|null>|null the fields before; null for `created` */
    #[ApiProperty(schema: ['type' => ['object', 'null'], 'properties' => Schemas::PRODUCT_STATE])]
    public ?array $before = null;

    /** @var array<string, string|int|null> */
    #[ApiProperty(schema: ['type' => 'object', 'properties' => Schemas::PRODUCT_STATE])]
    public array $after = [];

    public ?\DateTimeImmutable $occurredAt = null;
}
