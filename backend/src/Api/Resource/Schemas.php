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
            'productId' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'Null only on lines placed before lines were linked to products'],
            'sku' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'quantity' => ['type' => 'integer'],
            'reservedQuantity' => ['type' => 'integer', 'description' => 'Held in stock at the order\'s location: from confirmation until it ships or is cancelled, otherwise 0. reservedQuantity + shippedQuantity = quantity while the order holds stock'],
            'shippedQuantity' => ['type' => 'integer', 'description' => 'Units that have left in shipments'],
            'unitPrice' => ['type' => 'integer', 'description' => 'Minor units'],
            'lineTotal' => ['type' => 'integer', 'description' => 'Minor units'],
        ],
    ];

    public const array NEW_LINE = [
        'type' => 'object',
        'required' => ['sku', 'quantity', 'unitPrice'],
        'properties' => [
            'sku' => ['type' => 'string', 'maxLength' => 64, 'description' => 'A product\'s SKU; an unknown SKU is refused'],
            'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Default: the product\'s name'],
            'quantity' => ['type' => 'integer', 'minimum' => 1],
            'unitPrice' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Minor units of the order currency'],
        ],
    ];

    public const array NEW_SHIPMENT_LINE = [
        'type' => 'object',
        'required' => ['lineId', 'quantity'],
        'properties' => [
            'lineId' => ['type' => 'string', 'format' => 'uuid', 'description' => 'An order line\'s id'],
            'quantity' => ['type' => 'integer', 'minimum' => 1, 'description' => 'At most what the line has left to ship'],
        ],
    ];

    public const array SHIPMENT = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'location' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'name' => ['type' => 'string']]],
            'carrier' => ['type' => ['string', 'null']],
            'trackingNumber' => ['type' => ['string', 'null']],
            'shippedAt' => ['type' => 'string', 'format' => 'date-time'],
            'actor' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string']]],
            'voidedAt' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'Set when the shipment was taken back; it no longer counts'],
            'voidedBy' => ['type' => ['object', 'null'], 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string']]],
            'voidReason' => ['type' => ['string', 'null']],
            'voidable' => ['type' => 'boolean', 'description' => 'Whether it can be voided now'],
            'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'lineId' => ['type' => 'string', 'format' => 'uuid'],
                'position' => ['type' => 'integer'],
                'sku' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'quantity' => ['type' => 'integer'],
            ]]],
        ],
    ];

    public const array LOCATION_REF = [
        'type' => ['object', 'null'],
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'code' => ['type' => 'string'],
            'name' => ['type' => 'string'],
        ],
    ];

    public const array EVENT = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'type' => ['type' => 'string', 'enum' => ['created', 'transition', 'shipment', 'shipment_corrected', 'shipment_voided', 'note', 'tags_changed', 'payment_status_changed']],
            'transition' => ['type' => ['string', 'null'], 'description' => 'Set on a transition event'],
            'actor' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string']]],
            'before' => ['type' => ['object', 'null'], 'description' => 'What changed, before: {status, heldFrom}, {tags} or {paymentStatus}'],
            'after' => ['type' => ['object', 'null'], 'description' => 'What changed, after; a note event has {note}'],
            'occurredAt' => ['type' => 'string', 'format' => 'date-time'],
        ],
    ];

    public const array TAG = ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[^,]+$'];

    public const array PAYMENT_STATUS = ['type' => 'string', 'enum' => ['unpaid', 'authorized', 'paid', 'refunded', 'partially_refunded']];

    private function __construct()
    {
    }
}
