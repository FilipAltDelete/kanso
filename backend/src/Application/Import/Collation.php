<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Import;

/**
 * How MySQL's utf8mb4_0900_ai_ci collation compares codes such as SKUs and
 * external references: without case or accents. Two values with the same
 * key are one value to a unique index.
 */
final class Collation
{
    private function __construct()
    {
    }

    public static function key(string $value): string
    {
        $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);

        return mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', false === $decomposed ? $value : $decomposed));
    }
}
