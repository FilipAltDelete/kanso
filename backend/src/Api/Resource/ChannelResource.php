<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Kanso\Core\Internal\Api\State\ChannelProvider;

/** Where orders come from. Read-only for now: `manual` is seeded; connectors add theirs in Phase 2. */
#[ApiResource(
    shortName: 'Channel',
    operations: [
        new GetCollection(uriTemplate: '/channels', provider: ChannelProvider::class),
        new Get(uriTemplate: '/channels/{code}', uriVariables: ['code'], provider: ChannelProvider::class),
    ],
    security: "is_granted('ROLE_VIEWER')",
    paginationEnabled: false,
)]
final class ChannelResource
{
    #[ApiProperty(identifier: true)]
    public string $code = '';
    public string $name = '';
    public string $type = '';
    /** ISO 4217; the default currency of an order created in this channel. */
    public string $currency = '';
}
