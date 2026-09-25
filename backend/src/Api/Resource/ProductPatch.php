<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

/**
 * A merge patch: a property left out of the request stays uninitialized here
 * and unchanged on the product. `version` is required.
 */
final class ProductPatch
{
    public string $name;
    public ?string $barcode;
    public ?int $weightGrams;
    public int $version;
}
