<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/** The body of POST /api/orders/{id}/shipments/{shipmentId}/void. */
final class ShipmentVoidInput
{
    #[ApiProperty(description: 'The order version you last saw; a different one is a 409.', schema: ['type' => 'integer'], required: true)]
    public mixed $version = null;

    #[ApiProperty(description: 'Why, for the history: "recorded twice", "parcel never collected".', schema: ['type' => 'string', 'maxLength' => 500])]
    public mixed $reason = null;
}
