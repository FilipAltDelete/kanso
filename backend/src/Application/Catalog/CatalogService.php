<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Catalog;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Inventory\LocationStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * Creating and editing products and locations. Edits carry the version the
 * caller read, so two people editing the same product cannot silently
 * overwrite each other.
 */
final class CatalogService
{
    public function __construct(
        private readonly ProductStoreInterface $products,
        private readonly LocationStoreInterface $locations,
        private readonly TransactionInterface $transaction,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createProduct(string $sku, string $name, ?string $barcode, ?int $weightGrams): Product
    {
        $sku = trim($sku);
        $violations = [
            ...ProductRules::sku($sku),
            ...ProductRules::fields($name, $barcode, $weightGrams),
        ];
        if ([] === $violations && null !== $this->products->findBySku($sku)) {
            $violations[] = ['path' => 'sku', 'message' => \sprintf('SKU "%s" already exists.', $sku), 'code' => 'taken'];
        }
        self::throwIfAny($violations);

        return $this->write(function () use ($sku, $name, $barcode, $weightGrams): Product {
            $product = new Product($sku, trim($name), self::blankToNull($barcode), $weightGrams, $this->clock->now());
            $this->products->add($product);

            return $product;
        });
    }

    public function updateProduct(Product $product, int $expectedVersion, string $name, ?string $barcode, ?int $weightGrams): Product
    {
        self::throwIfAny(ProductRules::fields($name, $barcode, $weightGrams));
        self::assertVersion('product', $product->version(), $expectedVersion);

        return $this->write(function () use ($product, $name, $barcode, $weightGrams): Product {
            $product->update(trim($name), self::blankToNull($barcode), $weightGrams, $this->clock->now());

            return $product;
        });
    }

    public function createLocation(string $code, string $name, Address $address): Location
    {
        $code = trim($code);
        $address = self::normalizeAddress($address);
        $violations = [
            ...self::check('code', 1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/', $code), 'A location code is 1–32 letters, digits, "-" or "_".', 'format'),
            ...$this->locationFields($name, $address),
        ];
        if ([] === $violations && null !== $this->locations->findByCode($code)) {
            $violations[] = ['path' => 'code', 'message' => \sprintf('Location "%s" already exists.', $code), 'code' => 'taken'];
        }
        self::throwIfAny($violations);

        return $this->write(function () use ($code, $name, $address): Location {
            $location = new Location($code, trim($name), $address, $this->clock->now());
            $this->locations->add($location);

            return $location;
        });
    }

    public function updateLocation(Location $location, int $expectedVersion, string $name, Address $address): Location
    {
        $address = self::normalizeAddress($address);
        self::throwIfAny($this->locationFields($name, $address));
        self::assertVersion('location', $location->version(), $expectedVersion);

        return $this->write(function () use ($location, $name, $address): Location {
            $location->update(trim($name), $address, $this->clock->now());

            return $location;
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function write(callable $work): mixed
    {
        try {
            return $this->transaction->run($work);
        } catch (ConcurrentModification) {
            throw new Conflict('Someone else saved a change to this at the same time. Reload and try again.');
        }
    }

    /** @return list<array{path: string, message: string, code: string}> */
    private function locationFields(string $name, Address $address): array
    {
        return [
            ...self::check('name', '' !== trim($name) && mb_strlen(trim($name)) <= 128, 'A location needs a name of at most 128 characters.', 'required'),
            ...self::check('addressLine1', mb_strlen($address->line1 ?? '') <= 128, 'At most 128 characters.', 'length'),
            ...self::check('addressLine2', mb_strlen($address->line2 ?? '') <= 128, 'At most 128 characters.', 'length'),
            ...self::check('postalCode', mb_strlen($address->postalCode ?? '') <= 16, 'At most 16 characters.', 'length'),
            ...self::check('city', mb_strlen($address->city ?? '') <= 64, 'At most 64 characters.', 'length'),
            ...self::check('countryCode', null === $address->countryCode || 1 === preg_match('/^[A-Z]{2}$/', $address->countryCode), 'A country is a two-letter ISO 3166 code, such as SE.', 'format'),
        ];
    }

    private static function normalizeAddress(Address $address): Address
    {
        $country = self::blankToNull($address->countryCode);

        return new Address(
            self::blankToNull($address->line1),
            self::blankToNull($address->line2),
            self::blankToNull($address->postalCode),
            self::blankToNull($address->city),
            null === $country ? null : strtoupper($country),
        );
    }

    private static function assertVersion(string $what, int $current, int $expected): void
    {
        if ($current !== $expected) {
            throw new Conflict(\sprintf('This %s was changed by someone else (version %d, you edited %d). Reload and try again.', $what, $current, $expected));
        }
    }

    /** @return list<array{path: string, message: string, code: string}> */
    private static function check(string $path, bool $ok, string $message, string $code): array
    {
        return ProductRules::check($path, $ok, $message, $code);
    }

    /** @param list<array{path: string, message: string, code: string}> $violations */
    private static function throwIfAny(array $violations): void
    {
        if ([] !== $violations) {
            throw new ValidationFailed($violations);
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        return ProductRules::blankToNull($value);
    }
}
