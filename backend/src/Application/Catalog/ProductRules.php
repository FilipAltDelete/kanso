<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Catalog;

/**
 * What a valid product is, shared by the API's create and edit and by the
 * CSV import, so a row the import accepts is one the API would accept too.
 */
final class ProductRules
{
    public const int MAX_WEIGHT_GRAMS = 10_000_000;

    private function __construct()
    {
    }

    /** @return list<array{path: string, message: string, code: string}> */
    public static function sku(string $sku): array
    {
        return self::check('sku', 1 === preg_match('/^\S{1,64}$/u', $sku), 'A SKU is 1–64 characters with no spaces.', 'format');
    }

    /** @return list<array{path: string, message: string, code: string}> */
    public static function fields(string $name, ?string $barcode, ?int $weightGrams): array
    {
        $barcode = self::blankToNull($barcode);

        return [
            ...self::check('name', '' !== trim($name) && mb_strlen(trim($name)) <= 255, 'A product needs a name of at most 255 characters.', 'required'),
            ...self::check('barcode', null === $barcode || 1 === preg_match('/^\S{1,64}$/u', $barcode), 'A barcode is at most 64 characters with no spaces.', 'format'),
            ...self::check('weightGrams', null === $weightGrams || ($weightGrams >= 0 && $weightGrams <= self::MAX_WEIGHT_GRAMS), \sprintf('Weight is whole grams between 0 and %d.', self::MAX_WEIGHT_GRAMS), 'range'),
        ];
    }

    public static function blankToNull(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }

    /** @return list<array{path: string, message: string, code: string}> */
    public static function check(string $path, bool $ok, string $message, string $code): array
    {
        return $ok ? [] : [['path' => $path, 'message' => $message, 'code' => $code]];
    }
}
