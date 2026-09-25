<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

/** What a new location is created from. */
final class LocationInput
{
    public string $code = '';
    public string $name = '';
    public ?string $addressLine1 = null;
    public ?string $addressLine2 = null;
    public ?string $postalCode = null;
    public ?string $city = null;
    /** ISO 3166-1 alpha-2. */
    public ?string $countryCode = null;
}
