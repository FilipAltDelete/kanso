<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Catalog;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Import\Collation;
use Kanso\Core\Internal\Application\Import\CsvFile;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Catalog\ProductEvent;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Psr\Clock\ClockInterface;

/**
 * Creates and updates products from a CSV file, matched by SKU (ADR-0006).
 *
 * The file has a header row naming its columns: `sku` and `name` always,
 * `barcode` and `weightGrams` optionally. A column that is left out leaves
 * that field alone on existing products; a column with an empty cell clears
 * it. A row equal to the product it matches is not written, so importing the
 * same file twice changes nothing the second time.
 *
 * Every row is checked before anything is written. Rows that fail are
 * reported and skipped; the rest are written in one transaction. A dry run
 * does the checking and the counting and writes nothing — the preview.
 */
final class ProductImporter
{
    public const int MAX_ROWS = CsvFile::MAX_ROWS;
    public const int MAX_BYTES = CsvFile::MAX_BYTES;

    /** Field => the column that holds it. */
    private const array COLUMNS = ['sku' => 'sku', 'name' => 'name', 'barcode' => 'barcode', 'weightGrams' => 'weightGrams'];
    private const array REQUIRED = ['sku', 'name'];

    public function __construct(
        private readonly ProductStoreInterface $products,
        private readonly TransactionInterface $transaction,
        private readonly ClockInterface $clock,
    ) {
    }

    /** `$actor` is who the products' history names; left out, the system. */
    public function import(string $csv, bool $dryRun, ?Actor $actor = null): ProductImportResult
    {
        $file = CsvFile::read($csv, self::COLUMNS, self::REQUIRED);

        $errors = [];
        /** @var array<int, array{sku: string, name: string, barcode?: ?string, weightGrams?: ?int}> $valid row number => values */
        $valid = [];
        /** @var array<string, int> $seen folded SKU => the row it was first seen on */
        $seen = [];

        foreach (array_keys($file->rows) as $row) {
            $rowErrors = [];
            $values = self::values($file, $row, $rowErrors);
            $sku = $values['sku'] ?? null;

            if (null !== $values) {
                foreach ([...ProductRules::sku($values['sku']), ...ProductRules::fields($values['name'], $values['barcode'] ?? null, $values['weightGrams'] ?? null)] as $violation) {
                    $rowErrors[] = [$violation['path'], $violation['code'], $violation['message']];
                }
            }
            if (null !== $sku && '' !== $sku) {
                $key = Collation::key($sku);
                if (isset($seen[$key])) {
                    $rowErrors[] = ['sku', 'duplicate', \sprintf('The SKU is also on row %d; each SKU can be on one row only.', $seen[$key])];
                } else {
                    $seen[$key] = $row;
                }
            }

            if ([] === $rowErrors && null !== $values) {
                $valid[$row] = $values;
            }
            foreach ($rowErrors as [$field, $code, $message]) {
                $errors[] = ['row' => $row, 'sku' => '' === $sku ? null : $sku, 'field' => $field, 'code' => $code, 'message' => $message];
            }
        }

        // MySQL compares SKUs without regard to case or accents, so "abc" in
        // the file finds "ABC" in the table. That is a different spelling of
        // an existing SKU, not a new product; it is reported, not guessed at.
        $existing = [];
        foreach ($this->products->findBySkus(array_column($valid, 'sku')) as $product) {
            $existing[Collation::key($product->sku())] = $product;
        }

        $creates = $updates = [];
        $unchanged = 0;
        foreach ($valid as $row => $values) {
            $product = $existing[Collation::key($values['sku'])] ?? null;
            if (null === $product) {
                $creates[] = $values;
            } elseif ($product->sku() !== $values['sku']) {
                $errors[] = ['row' => $row, 'sku' => $values['sku'], 'field' => 'sku', 'code' => 'spelling', 'message' => \sprintf('The product "%s" already exists; SKUs differing only in case or accents are the same SKU.', $product->sku())];
            } elseif (self::changes($product, $values)) {
                $updates[] = [$product, $values];
            } else {
                ++$unchanged;
            }
        }

        if (!$dryRun && ([] !== $creates || [] !== $updates)) {
            $this->write($creates, $updates, $actor ?? CatalogService::system());
        }

        usort($errors, static fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return new ProductImportResult(
            $dryRun,
            \count($file->rows),
            \count($creates),
            \count($updates),
            $unchanged,
            \count(array_unique(array_column($errors, 'row'))),
            $errors,
        );
    }

    /**
     * One row as product fields, or null when the row cannot be read.
     *
     * @param list<array{string, string, string}> $errors field, code, message
     *
     * @return array{sku: string, name: string, barcode?: ?string, weightGrams?: ?int}|null
     */
    private static function values(CsvFile $file, int $row, array &$errors): ?array
    {
        $byField = $file->values($row);
        if (null === $byField) {
            $errors[] = CsvFile::strayCell();

            return null;
        }

        $values = ['sku' => $byField['sku'] ?? '', 'name' => $byField['name'] ?? ''];
        if (\array_key_exists('barcode', $byField)) {
            $values['barcode'] = ProductRules::blankToNull($byField['barcode']);
        }
        if (\array_key_exists('weightGrams', $byField)) {
            $values['weightGrams'] = self::grams($byField['weightGrams'], $errors);
        }

        return $values;
    }

    /** @param list<array{string, string, string}> $errors */
    private static function grams(string $cell, array &$errors): ?int
    {
        if ('' === $cell) {
            return null;
        }
        if (1 !== preg_match('/^\d{1,9}$/', $cell)) {
            $errors[] = ['weightGrams', 'integer', 'Weight is whole grams, digits only, such as 180.'];

            return null;
        }

        return (int) $cell;
    }

    /** @param array{sku: string, name: string, barcode?: ?string, weightGrams?: ?int} $values */
    private static function changes(Product $product, array $values): bool
    {
        return $product->name() !== $values['name']
            || (\array_key_exists('barcode', $values) && $product->barcode() !== $values['barcode'])
            || (\array_key_exists('weightGrams', $values) && $product->weightGrams() !== $values['weightGrams']);
    }

    /**
     * @param list<array{sku: string, name: string, barcode?: ?string, weightGrams?: ?int}>                 $creates
     * @param list<array{Product, array{sku: string, name: string, barcode?: ?string, weightGrams?: ?int}}> $updates
     */
    private function write(array $creates, array $updates, Actor $actor): void
    {
        $now = $this->clock->now();
        try {
            $this->transaction->run(function () use ($creates, $updates, $actor, $now): void {
                foreach ($creates as $values) {
                    $product = new Product($values['sku'], $values['name'], $values['barcode'] ?? null, $values['weightGrams'] ?? null, $now);
                    $this->products->add($product);
                    $this->products->addEvent(ProductEvent::created($product, ProductEvent::SOURCE_IMPORT, $actor, $now));
                }
                foreach ($updates as [$product, $values]) {
                    $before = ProductEvent::state($product);
                    $product->update(
                        $values['name'],
                        \array_key_exists('barcode', $values) ? $values['barcode'] : $product->barcode(),
                        \array_key_exists('weightGrams', $values) ? $values['weightGrams'] : $product->weightGrams(),
                        $now,
                    );
                    // Only rows that differ are updates, so there is always a change to record.
                    $event = ProductEvent::updated($product, $before, ProductEvent::SOURCE_IMPORT, $actor, $now);
                    if (null !== $event) {
                        $this->products->addEvent($event);
                    }
                }
            });
        } catch (ConcurrentModification) {
            throw new Conflict('Some of these products were changed or created by someone else during the import. Nothing was imported; run the import again.');
        }
    }
}
