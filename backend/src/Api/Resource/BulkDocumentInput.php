<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The body of POST /api/orders/bulk-documents. Values are taken as sent and
 * checked by DocumentService, so a wrong type is a violation at its path.
 */
final class BulkDocumentInput
{
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['pick_list', 'packing_slip']], required: true)]
    public mixed $type = null;

    #[ApiProperty(description: 'The language the documents are printed in. Default en.', schema: ['type' => 'string', 'enum' => ['sv', 'en']])]
    public mixed $locale = null;

    #[ApiProperty(description: 'The orders to print, at most 100.', schema: ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => ['type' => 'string', 'format' => 'uuid']], required: true)]
    public mixed $orderIds = null;
}
