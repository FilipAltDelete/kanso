<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

/**
 * Which properties a merge-patch body actually carried. Patch inputs declare
 * their properties without defaults, so one the client left out stays
 * uninitialized — and "left out" means "unchanged", while `null` means "clear".
 */
final class Sent
{
    public static function has(object $input, string $property): bool
    {
        $reflection = new \ReflectionProperty($input, $property);

        return $reflection->isInitialized($input);
    }
}
