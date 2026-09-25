<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

final class TransitionInput
{
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['confirm', 'allocate', 'start_picking', 'pack', 'ship', 'deliver', 'cancel', 'hold', 'release']], required: true)]
    public mixed $transition = null;

    #[ApiProperty(description: 'The order version the caller last saw.', schema: ['type' => 'integer'], required: true)]
    public mixed $version = null;
}
