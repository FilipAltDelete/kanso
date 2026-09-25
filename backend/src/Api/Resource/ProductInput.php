<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

/** What a new product is created from. */
final class ProductInput
{
    public string $sku = '';
    public string $name = '';
    public ?string $barcode = null;
    /** Whole grams. */
    public ?int $weightGrams = null;
}
