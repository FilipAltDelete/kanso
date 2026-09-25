<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * A merge patch: a property left out of the request stays uninitialized here
 * and unchanged on the user. Values are checked by UserService.
 */
final class UserPatch
{
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'email', 'maxLength' => 180])]
    public mixed $email;

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => 128])]
    public mixed $name;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['ROLE_ADMIN', 'ROLE_OPERATOR', 'ROLE_VIEWER']])]
    public mixed $role;
}
