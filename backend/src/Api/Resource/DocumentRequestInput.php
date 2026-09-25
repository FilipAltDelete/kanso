<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

final class DocumentRequestInput
{
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['pick_list', 'packing_slip']], required: true)]
    public mixed $type = null;

    #[ApiProperty(description: 'The language the document is printed in. Default en.', schema: ['type' => 'string', 'enum' => ['sv', 'en']])]
    public mixed $locale = null;
}
