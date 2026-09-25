<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Kanso\Core\Internal\Api\State\ListRequest;
use Kanso\Core\Internal\Api\State\ProductProcessor;
use Kanso\Core\Internal\Api\State\ProductProvider;

/**
 * A product (SKU). `onHand`, `reserved` and `available` are summed over every
 * location; per-location stock is `/api/inventory-levels?product=<id>`.
 */
#[ApiResource(
    shortName: 'Product',
    operations: [
        new GetCollection(
            uriTemplate: '/products',
            provider: ProductProvider::class,
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string'], description: 'Matches the start of the SKU, any part of the name, or the whole barcode.'),
                'sort' => new QueryParameter(schema: ['type' => 'string'], description: 'Comma-separated fields, "-" for descending: sku, name, createdAt, updatedAt. Default sku.'),
            ],
        ),
        new Get(uriTemplate: '/products/{id}', provider: ProductProvider::class),
        new Post(
            uriTemplate: '/products',
            input: ProductInput::class,
            processor: ProductProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
        ),
        new Patch(
            uriTemplate: '/products/{id}',
            input: ProductPatch::class,
            provider: ProductProvider::class,
            processor: ProductProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
            openapi: new OpenApiOperation(description: 'Send `version` as last read; a different current version is a 409. The SKU cannot be changed.'),
        ),
    ],
    security: "is_granted('ROLE_VIEWER')",
    // Every field, every time: a null says "not set", an absent key would say nothing.
    normalizationContext: ['skip_null_values' => false],
)]
final class ProductResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
    public string $sku = '';
    public string $name = '';
    public ?string $barcode = null;
    /** Whole grams. */
    public ?int $weightGrams = null;
    /** Optimistic lock: send it back when editing. */
    public int $version = 0;
    #[ApiProperty(writable: false)]
    public int $onHand = 0;
    #[ApiProperty(writable: false)]
    public int $reserved = 0;
    #[ApiProperty(writable: false)]
    public int $available = 0;
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;
}
