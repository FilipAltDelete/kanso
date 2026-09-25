<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use Kanso\Core\Internal\Api\State\OrderTagProvider;

/** A tag in use on orders, for the list's tag filter and for suggestions. */
#[ApiResource(
    shortName: 'OrderTag',
    operations: [
        new GetCollection(
            uriTemplate: '/order-tags',
            provider: OrderTagProvider::class,
            openapi: new Operation(summary: 'Every tag on at least one order, by name, with how many orders have it.'),
        ),
    ],
    security: "is_granted('ROLE_VIEWER')",
    paginationEnabled: false,
)]
final class OrderTagResource
{
    #[ApiProperty(identifier: true)]
    public string $name = '';

    public int $orders = 0;
}
