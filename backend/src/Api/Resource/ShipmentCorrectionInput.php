<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The body of POST /api/orders/{id}/shipments/{shipmentId}/tracking. A field
 * left out stays uninitialized here and unchanged on the shipment; `null`
 * clears it.
 */
final class ShipmentCorrectionInput
{
    #[ApiProperty(description: 'The order version you last saw; a different one is a 409.', schema: ['type' => 'integer'], required: true)]
    public mixed $version;

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => 64])]
    public mixed $carrier;

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => 128])]
    public mixed $trackingNumber;
}
