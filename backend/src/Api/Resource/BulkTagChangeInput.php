<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

final class BulkTagChangeInput extends TagChangeInput
{
    #[ApiProperty(description: 'Ids of the orders to change, at most 500.', schema: ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => ['type' => 'string', 'format' => 'uuid']], required: true)]
    public mixed $orders = null;
}
