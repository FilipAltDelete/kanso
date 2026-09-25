<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Catalog;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
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
    public const int MAX_ROWS = 5000;
    /** The proxy's `client_max_body_size`; a larger body never reaches PHP. */
    public const int MAX_BYTES = 1024 * 1024;

    /** Header spellings, folded (see header()), to the field they fill. */
    private const array COLUMNS = ['sku' => 'sku', 'name' => 'name', 'barcode' => 'barcode', 'weightgrams' => 'weightGrams'];
    private const array REQUIRED = ['sku', 'name'];

    public function __construct(
        private readonly ProductStoreInterface $products,
        private readonly TransactionInterface $transaction,
        private readonly ClockInterface $clock,
    ) {
    }

    public function import(string $csv, bool $dryRun): ProductImportResult
    {
        [$columns, $records] = $this->read($csv);

        $errors = [];
        /** @var array<int, array{sku: string, name: string, barcode?: ?string, weightGrams?: ?int}> $valid row number => values */
        $valid = [];
        /** @var array<string, int> $seen folded SKU => the row it was first seen on */
        $seen = [];

        foreach ($records as $row => $cells) {
            $rowErrors = [];
            $values = $this->values($columns, $cells, $rowErrors);
            $sku = $values['sku'] ?? null;

            if (null !== $values) {
                foreach ([...ProductRules::sku($values['sku']), ...ProductRules::fields($values['name'], $values['barcode'] ?? null, $values['weightGrams'] ?? null)] as $violation) {
                    $rowErrors[] = [$violation['path'], $violation['code'], $violation['message']];
                }
            }
            if (null !== $sku && '' !== $sku) {
                $key = self::fold($sku);
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
            $existing[self::fold($product->sku())] = $product;
        }

        $creates = $updates = [];
        $unchanged = 0;
        foreach ($valid as $row => $values) {
            $product = $existing[self::fold($values['sku'])] ?? null;
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
            $this->write($creates, $updates);
        }

        usort($errors, static fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return new ProductImportResult(
            $dryRun,
            \count($records),
            \count($creates),
            \count($updates),
            $unchanged,
            \count(array_unique(array_column($errors, 'row'))),
            $errors,
        );
    }

    /**
     * The header and the data rows, keyed by row number. Problems with the
     * file as a whole are a 422 and nothing is imported: `path` is `file`, or
     * `header.<column>` for a problem with one column.
     *
     * @return array{array<int, string>, array<int, list<?string>>} column index => field, and row number => cells
     */
    private function read(string $csv): array
    {
        if (\strlen($csv) > self::MAX_BYTES) {
            self::fileError('too_large', \sprintf('The file is larger than %d bytes.', self::MAX_BYTES));
        }
        if (str_starts_with($csv, "\u{FEFF}")) {
            $csv = substr($csv, 3);
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            self::fileError('encoding', 'The file is not UTF-8. In Excel, save it as "CSV UTF-8".');
        }

        $stream = fopen('php://temp', 'r+');
        \assert(false !== $stream);
        fwrite($stream, $csv);
        rewind($stream);
        $delimiter = self::delimiter($csv);

        $header = null;
        $records = [];
        $row = 0;
        while (false !== ($cells = fgetcsv($stream, null, $delimiter, '"', ''))) {
            ++$row;
            if ([null] === $cells) {
                continue; // a blank line
            }
            if (null === $header) {
                $header = $cells;
                continue;
            }
            if (self::MAX_ROWS === \count($records)) {
                fclose($stream);
                self::fileError('too_many_rows', \sprintf('The file has more than %d rows. Split it into smaller files.', self::MAX_ROWS));
            }
            $records[$row] = $cells;
        }
        fclose($stream);

        if (null === $header) {
            self::fileError('empty', 'The file is empty.');
        }
        if ([] === $records) {
            self::fileError('no_rows', 'The file has a header but no rows.');
        }

        return [$this->header($header), $records];
    }

    /**
     * @param list<?string> $cells
     *
     * @return array<int, string>
     */
    private function header(array $cells): array
    {
        $columns = [];
        $violations = [];
        foreach ($cells as $index => $cell) {
            $name = trim((string) $cell);
            if ('' === $name) {
                continue; // spreadsheet padding; a value under it is caught per row
            }
            $field = self::COLUMNS[strtolower(str_replace([' ', '_', '-'], '', $name))] ?? null;
            if (null === $field) {
                $violations[] = ['path' => 'header.'.$name, 'message' => \sprintf('Unknown column "%s". The columns are sku, name, barcode and weightGrams.', $name), 'code' => 'unknown_column'];
            } elseif (\in_array($field, $columns, true)) {
                $violations[] = ['path' => 'header.'.$name, 'message' => \sprintf('The column "%s" appears twice.', $name), 'code' => 'duplicate_column'];
            } else {
                $columns[$index] = $field;
            }
        }
        foreach (self::REQUIRED as $field) {
            if (!\in_array($field, $columns, true)) {
                $violations[] = ['path' => 'header.'.$field, 'message' => \sprintf('The column "%s" is missing.', $field), 'code' => 'missing_column'];
            }
        }
        if ([] !== $violations) {
            throw new ValidationFailed($violations);
        }

        return $columns;
    }

    /**
     * One row's cells as field values, or null when the row cannot be read.
     *
     * @param array<int, string>                  $columns
     * @param list<?string>                       $cells
     * @param list<array{string, string, string}> $errors  field, code, message
     *
     * @return array{sku: string, name: string, barcode?: ?string, weightGrams?: ?int}|null
     */
    private function values(array $columns, array $cells, array &$errors): ?array
    {
        // Spreadsheets pad rows with empty cells; only a value outside the named columns is a problem.
        foreach ($cells as $index => $cell) {
            if (!isset($columns[$index]) && '' !== trim((string) $cell)) {
                $errors[] = ['row', 'stray_cell', \sprintf('Column %d has a value but no header.', $index + 1)];

                return null;
            }
        }

        $byField = [];
        foreach ($columns as $index => $field) {
            $byField[$field] = trim((string) ($cells[$index] ?? ''));
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
    private function write(array $creates, array $updates): void
    {
        $now = $this->clock->now();
        try {
            $this->transaction->run(function () use ($creates, $updates, $now): void {
                foreach ($creates as $values) {
                    $this->products->add(new Product($values['sku'], $values['name'], $values['barcode'] ?? null, $values['weightGrams'] ?? null, $now));
                }
                foreach ($updates as [$product, $values]) {
                    $product->update(
                        $values['name'],
                        \array_key_exists('barcode', $values) ? $values['barcode'] : $product->barcode(),
                        \array_key_exists('weightGrams', $values) ? $values['weightGrams'] : $product->weightGrams(),
                        $now,
                    );
                }
            });
        } catch (ConcurrentModification) {
            throw new Conflict('Some of these products were changed or created by someone else during the import. Nothing was imported; run the import again.');
        }
    }

    /** Excel writes ";" where the decimal separator is a comma, as in Swedish; others write ",". */
    private static function delimiter(string $csv): string
    {
        $firstLine = strtok($csv, "\r\n");
        $firstLine = false === $firstLine ? '' : $firstLine;
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** A SKU as MySQL's utf8mb4_0900_ai_ci collation compares it: without case or accents. */
    private static function fold(string $sku): string
    {
        $decomposed = \Normalizer::normalize($sku, \Normalizer::FORM_D);

        return mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', false === $decomposed ? $sku : $decomposed));
    }

    private static function fileError(string $code, string $message): never
    {
        throw new ValidationFailed([['path' => 'file', 'message' => $message, 'code' => $code]]);
    }
}
