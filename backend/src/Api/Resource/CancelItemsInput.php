<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/** The body of POST /api/orders/{id}/cancellations: a partial cancel. */
final class CancelItemsInput
{
    #[ApiProperty(description: 'The order version you last saw; a different one is a 409.', schema: ['type' => 'integer'], required: true)]
    public mixed $version = null;

    #[ApiProperty(description: 'Which lines, and how many units of each: at most what the line has left to ship.', schema: ['type' => 'array', 'minItems' => 1, 'items' => Schemas::NEW_SHIPMENT_LINE], required: true)]
    public mixed $lines = null;

    #[ApiProperty(description: 'Why, in a few words; kept in the order\'s history.', schema: ['type' => 'string', 'maxLength' => 255])]
    public mixed $reason = null;
}
