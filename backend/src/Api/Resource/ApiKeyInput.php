<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The body of POST /api/api-keys. Values are taken as sent and checked by
 * ApiKeyService, so a wrong type is a violation at its path.
 */
final class ApiKeyInput
{
    #[ApiProperty(description: 'What the key is for, e.g. "Shopify sync".', schema: ['type' => 'string', 'maxLength' => 128], required: true)]
    public mixed $name = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['ROLE_OPERATOR', 'ROLE_VIEWER']], required: true)]
    public mixed $role = null;

    #[ApiProperty(description: 'When it stops working; in the future. Default never.', schema: ['type' => 'string', 'format' => 'date-time'])]
    public mixed $expiresAt = null;
}
