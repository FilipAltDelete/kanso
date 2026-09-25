<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

/**
 * A merge patch: a property left out of the request stays uninitialized here
 * and unchanged on the location. `version` is required.
 */
final class LocationPatch
{
    public string $name;
    public ?string $addressLine1;
    public ?string $addressLine2;
    public ?string $postalCode;
    public ?string $city;
    public ?string $countryCode;
    public int $version;
}
