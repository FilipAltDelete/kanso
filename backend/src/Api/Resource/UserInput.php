<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The body of POST /api/users. Values are taken as sent and checked by
 * UserService, so a wrong type is a violation at its path.
 */
final class UserInput
{
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'email', 'maxLength' => 180], required: true)]
    public mixed $email = null;

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => 128])]
    public mixed $name = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['ROLE_ADMIN', 'ROLE_OPERATOR', 'ROLE_VIEWER']], required: true)]
    public mixed $role = null;

    #[ApiProperty(description: 'The first password, at least 8 characters.', schema: ['type' => 'string', 'minLength' => 8, 'maxLength' => 1024, 'format' => 'password'], required: true)]
    public mixed $password = null;
}
