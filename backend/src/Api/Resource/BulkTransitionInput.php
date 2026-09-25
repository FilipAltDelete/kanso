<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/** The body of POST /api/orders/bulk-transitions. Checked by BulkTransitions, so a wrong type is a violation like any other. */
final class BulkTransitionInput
{
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['confirm', 'allocate', 'start_picking', 'pack', 'deliver', 'cancel', 'hold', 'release']], required: true)]
    public mixed $transition = null;

    #[ApiProperty(
        description: 'The orders, each with the version you last saw (optional).',
        schema: ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'version' => ['type' => 'integer']]]],
        required: true,
    )]
    public mixed $orders = null;
}
