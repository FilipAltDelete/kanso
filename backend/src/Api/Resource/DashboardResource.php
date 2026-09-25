<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Kanso\Core\Internal\Api\State\DashboardProvider;

/**
 * The operator's day at a glance: orders placed and shipped today, the
 * fulfillment queue, orders by status, and stock-outs. Read-only, and counted
 * fresh on every request; the UI asks every 30 seconds.
 */
#[ApiResource(
    shortName: 'Dashboard',
    operations: [
        new Get(
            uriTemplate: '/dashboard',
            uriVariables: [],
            provider: DashboardProvider::class,
            parameters: [
                'timeZone' => new QueryParameter(schema: ['type' => 'string'], description: 'The IANA time zone whose calendar day "today" is, e.g. Europe/Stockholm. Default UTC.'),
            ],
        ),
    ],
    security: "is_granted('ROLE_VIEWER')",
    normalizationContext: ['skip_null_values' => false],
)]
final class DashboardResource
{
    /** Today's date in `timeZone`, e.g. 2026-09-26. */
    #[ApiProperty(identifier: true)]
    public string $date = '';

    public string $timeZone = 'UTC';

    /** Today's bounds as UTC instants: from inclusive, to exclusive. */
    public ?\DateTimeImmutable $dayStart = null;

    public ?\DateTimeImmutable $dayEnd = null;

    /** Orders placed today. */
    public int $ordersToday = 0;

    /** Orders confirmed, allocated, picking or packed. */
    public int $awaitingFulfillment = 0;

    /** Orders with at least one shipment shipped today, partly shipped ones included. */
    public int $shippedToday = 0;

    /** @var array<string, int> */
    #[ApiProperty(schema: ['type' => 'object', 'description' => 'Every status with its count, zeros included.', 'additionalProperties' => ['type' => 'integer']])]
    public array $ordersByStatus = [];

    /** @var array{count: int, items: list<array<string, int|string>>} */
    #[ApiProperty(schema: [
        'type' => 'object',
        'description' => 'Product and location pairs with nothing available: the total, and the first ten by SKU.',
        'properties' => [
            'count' => ['type' => 'integer'],
            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'productId' => ['type' => 'string', 'format' => 'uuid'],
                'sku' => ['type' => 'string'],
                'productName' => ['type' => 'string'],
                'locationId' => ['type' => 'string', 'format' => 'uuid'],
                'locationCode' => ['type' => 'string'],
                'locationName' => ['type' => 'string'],
                'onHand' => ['type' => 'integer'],
                'reserved' => ['type' => 'integer'],
            ]]],
        ],
    ])]
    public array $stockOuts = ['count' => 0, 'items' => []];

    public ?\DateTimeImmutable $generatedAt = null;
}
