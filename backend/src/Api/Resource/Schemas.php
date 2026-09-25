<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

/** JSON Schemas for the nested order shapes, so the OpenAPI document describes them. */
final class Schemas
{
    public const array ADDRESS = [
        'type' => 'object',
        'required' => ['line1', 'postalCode', 'city', 'countryCode'],
        'properties' => [
            'name' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'line1' => ['type' => 'string', 'maxLength' => 255],
            'line2' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'postalCode' => ['type' => 'string', 'maxLength' => 32],
            'city' => ['type' => 'string', 'maxLength' => 128],
            'region' => ['type' => ['string', 'null'], 'maxLength' => 128],
            'countryCode' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$', 'description' => 'ISO 3166-1 alpha-2'],
            'phone' => ['type' => ['string', 'null'], 'maxLength' => 64],
        ],
    ];

    public const array LINE = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'position' => ['type' => 'integer'],
            'sku' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'quantity' => ['type' => 'integer'],
            'unitPrice' => ['type' => 'integer', 'description' => 'Minor units'],
            'lineTotal' => ['type' => 'integer', 'description' => 'Minor units'],
        ],
    ];

    public const array NEW_LINE = [
        'type' => 'object',
        'required' => ['sku', 'name', 'quantity', 'unitPrice'],
        'properties' => [
            'sku' => ['type' => 'string', 'maxLength' => 64],
            'name' => ['type' => 'string', 'maxLength' => 255],
            'quantity' => ['type' => 'integer', 'minimum' => 1],
            'unitPrice' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Minor units of the order currency'],
        ],
    ];

    public const array EVENT = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'type' => ['type' => 'string', 'enum' => ['created', 'transition']],
            'transition' => ['type' => ['string', 'null']],
            'actor' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string']]],
            'before' => ['type' => ['object', 'null']],
            'after' => ['type' => ['object', 'null']],
            'occurredAt' => ['type' => 'string', 'format' => 'date-time'],
        ],
    ];

    private function __construct()
    {
    }
}
