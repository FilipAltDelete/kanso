<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

final class NoteInput
{
    #[ApiProperty(description: 'Free text, 1 to 2000 characters.', schema: ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000], required: true)]
    public mixed $note = null;
}
