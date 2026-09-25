<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The body of POST /api/orders/{id}/shipments. Values are taken as sent and
 * checked by OrderService, so a wrong type is a violation at its path.
 */
final class ShipmentInput
{
    #[ApiProperty(description: 'The order version you last saw; a different one is a 409.', schema: ['type' => 'integer'], required: true)]
    public mixed $version = null;

    #[ApiProperty(description: 'Which lines, and how many units of each: part of a line is fine.', schema: ['type' => 'array', 'minItems' => 1, 'items' => Schemas::NEW_SHIPMENT_LINE], required: true)]
    public mixed $lines = null;

    #[ApiProperty(description: 'Free text, e.g. PostNord.', schema: ['type' => 'string', 'maxLength' => 64])]
    public mixed $carrier = null;

    #[ApiProperty(description: 'As the carrier gave it.', schema: ['type' => 'string', 'maxLength' => 128])]
    public mixed $trackingNumber = null;

    #[ApiProperty(description: 'When it left; default now.', schema: ['type' => 'string', 'format' => 'date-time'])]
    public mixed $shippedAt = null;
}
