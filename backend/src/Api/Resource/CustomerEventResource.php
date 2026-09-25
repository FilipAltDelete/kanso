<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use Kanso\Core\Internal\Api\State\CustomerEventProvider;

/** One entry in a customer's audit trail, newest first. */
#[ApiResource(
    shortName: 'CustomerEvent',
    operations: [
        new GetCollection(
            uriTemplate: '/customers/{customerId}/history',
            uriVariables: ['customerId'],
            provider: CustomerEventProvider::class,
            openapi: new Operation(summary: 'Who changed the customer, what, and when.'),
        ),
    ],
    security: "is_granted('ROLE_VIEWER')",
)]
final class CustomerEventResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    #[ApiProperty(identifier: false)]
    public string $customerId = '';

    /** "created" or "updated". */
    public string $type = '';

    /** Who made the change, as they were named then: a user's name or email, or an API key's name. */
    public string $actor = '';

    /** @var array<string, array{before: mixed, after: mixed}> */
    #[ApiProperty(schema: [
        'type' => 'object',
        'description' => 'Changed fields, each with its value before and after.',
        'additionalProperties' => ['type' => 'object', 'properties' => ['before' => [], 'after' => []]],
    ])]
    public array $changes = [];

    public ?\DateTimeImmutable $occurredAt = null;
}
