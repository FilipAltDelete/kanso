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
use Kanso\Core\Internal\Api\State\LocationProcessor;
use Kanso\Core\Internal\Api\State\LocationProvider;

/** A warehouse or store that holds stock. */
#[ApiResource(
    shortName: 'Location',
    operations: [
        new GetCollection(
            uriTemplate: '/locations',
            provider: LocationProvider::class,
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string'], description: 'Matches the start of the code, or any part of the name or city.'),
                'sort' => new QueryParameter(schema: ['type' => 'string'], description: 'Comma-separated fields, "-" for descending: code, name, city. Default code.'),
            ],
        ),
        new Get(uriTemplate: '/locations/{id}', provider: LocationProvider::class),
        new Post(
            uriTemplate: '/locations',
            input: LocationInput::class,
            processor: LocationProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
        ),
        new Patch(
            uriTemplate: '/locations/{id}',
            input: LocationPatch::class,
            provider: LocationProvider::class,
            processor: LocationProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
            openapi: new OpenApiOperation(description: 'Send `version` as last read; a different current version is a 409. The code cannot be changed.'),
        ),
    ],
    security: "is_granted('ROLE_VIEWER')",
    // Every field, every time: a null says "not set", an absent key would say nothing.
    normalizationContext: ['skip_null_values' => false],
)]
final class LocationResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
    public string $code = '';
    public string $name = '';
    public ?string $addressLine1 = null;
    public ?string $addressLine2 = null;
    public ?string $postalCode = null;
    public ?string $city = null;
    /** ISO 3166-1 alpha-2. */
    public ?string $countryCode = null;
    public int $version = 0;
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;
}
