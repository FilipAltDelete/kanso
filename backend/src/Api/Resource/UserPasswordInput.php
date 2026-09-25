<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/** The body of POST /api/users/{id}/password. */
final class UserPasswordInput
{
    #[ApiProperty(schema: ['type' => 'string', 'minLength' => 8, 'maxLength' => 1024, 'format' => 'password'], required: true)]
    public mixed $password = null;
}
