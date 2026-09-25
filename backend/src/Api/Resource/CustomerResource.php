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
use Kanso\Core\Internal\Api\State\CustomerProcessor;
use Kanso\Core\Internal\Api\State\CustomerProvider;

/**
 * A customer as the API shows it: a DTO, never the entity (as in Pimsen).
 * Anyone signed in can read customers; creating and changing them needs the
 * operator role. PATCH is a merge patch,
 * and `addresses`, when sent, replaces the whole list: send an address's `id`
 * to keep it, leave it out to add one.
 */
#[ApiResource(
    shortName: 'Customer',
    operations: [
        new GetCollection(
            uriTemplate: '/customers',
            provider: CustomerProvider::class,
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string'], description: 'Matches customers whose name or email contains this text.'),
                'sort' => new QueryParameter(schema: ['type' => 'string'], description: 'Comma-separated fields, "-" for descending: name, email, createdAt, updatedAt. Default -createdAt.'),
            ],
        ),
        new Get(uriTemplate: '/customers/{id}', provider: CustomerProvider::class),
        new Post(
            uriTemplate: '/customers',
            status: 201,
            processor: CustomerProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
        ),
        new Patch(
            uriTemplate: '/customers/{id}',
            inputFormats: ['json' => ['application/merge-patch+json', 'application/json']],
            provider: CustomerProvider::class,
            processor: CustomerProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
        ),
    ],
    security: "is_granted('ROLE_VIEWER')",
)]
final class CustomerResource
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?string $id = null;

    public ?string $email = null;

    public ?string $name = null;

    public ?string $phone = null;

    /** @var list<array<string, mixed>> */
    #[ApiProperty(
        description: 'Billing and shipping addresses, several of each; one default per type.',
        schema: [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'required' => ['type', 'line1', 'postalCode', 'city', 'countryCode'],
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Send it back to keep an existing address.'],
                    'type' => ['type' => 'string', 'enum' => ['billing', 'shipping']],
                    'isDefault' => ['type' => 'boolean'],
                    'name' => ['type' => ['string', 'null'], 'description' => 'The recipient, when not the customer.'],
                    'company' => ['type' => ['string', 'null']],
                    'line1' => ['type' => 'string'],
                    'line2' => ['type' => ['string', 'null']],
                    'postalCode' => ['type' => 'string'],
                    'city' => ['type' => 'string'],
                    'region' => ['type' => ['string', 'null']],
                    'countryCode' => ['type' => 'string', 'description' => 'ISO 3166-1 alpha-2, e.g. SE.'],
                    'phone' => ['type' => ['string', 'null']],
                ],
            ],
        ],
    )]
    public array $addresses = [];

    #[ApiProperty(writable: false)]
    public ?\DateTimeImmutable $createdAt = null;

    #[ApiProperty(writable: false)]
    public ?\DateTimeImmutable $updatedAt = null;
}
