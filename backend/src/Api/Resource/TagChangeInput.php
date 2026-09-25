<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/** Tags to add and to remove, compared without regard to case. At least one tag between them. */
class TagChangeInput
{
    #[ApiProperty(description: 'Tags to add; one the order already has is ignored.', schema: ['type' => 'array', 'maxItems' => 20, 'items' => Schemas::TAG])]
    public mixed $add = null;

    #[ApiProperty(description: 'Tags to remove; one the order does not have is ignored.', schema: ['type' => 'array', 'maxItems' => 20, 'items' => Schemas::TAG])]
    public mixed $remove = null;
}
