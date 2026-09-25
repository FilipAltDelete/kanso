<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Inventory;

use Kanso\Core\Internal\Application\Import\Collation;
use Kanso\Core\Internal\Application\Import\CsvFile;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Inventory\AdjustmentReason;
use Kanso\Core\Internal\Domain\Inventory\InventoryLevel;
use Kanso\Core\Internal\Domain\Inventory\InventoryMovement;
use Kanso\Core\Internal\Domain\Inventory\InventoryStoreInterface;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Inventory\LocationStoreInterface;
use Kanso\Core\Internal\Domain\Inventory\StockRuleViolated;
use Psr\Clock\ClockInterface;

/**
 * Sets on hand from a CSV file of counted quantities (ADR-0016, following
 * ADR-0006): the columns `sku`, `location` (its code) and `quantity`.
 *
 * Each row is a count, recorded like a manual adjustment with the reason
 * `count`: the level is set to the quantity and a movement records who,
 * when, before and after. A row whose quantity is already on hand is not
 * written at all, so importing the same file twice changes nothing the
 * second time (a manual count that matches is recorded; an import that
 * matches is not, or every re-run would add a movement per row).
 *
 * Every row is checked before anything is written. Rows that fail are
 * reported and skipped. The others are written one transaction per row,
 * each locking its level first, so a row that has become invalid in the
 * meantime (an order reserved stock above the count) fails alone and the
 * rest still go in. Reserved stock is never counted away: a quantity below
 * what is reserved for orders is refused, as in a manual adjustment.
 * A dry run does the checking and the counting and writes nothing.
 */
final class StockImporter
{
    /** Field => the column that holds it. */
    private const array COLUMNS = ['sku' => 'sku', 'location' => 'location', 'quantity' => 'quantity'];
    private const array REQUIRED = ['sku', 'location', 'quantity'];

    public function __construct(
        private readonly ProductStoreInterface $products,
        private readonly LocationStoreInterface $locations,
        private readonly InventoryStoreInterface $inventory,
        private readonly TransactionInterface $transaction,
        private readonly ClockInterface $clock,
    ) {
    }

    public function import(string $csv, bool $dryRun, Actor $actor): StockImportResult
    {
        $file = CsvFile::read($csv, self::COLUMNS, self::REQUIRED);

        $errors = [];
        /** @var array<int, array{sku: string, location: string, quantity: int}> $read row number => values */
        $read = [];
        foreach (array_keys($file->rows) as $row) {
            $rowErrors = [];
            $values = self::values($file, $row, $rowErrors);
            if ([] === $rowErrors && null !== $values) {
                $read[$row] = $values;
            }
            foreach ($rowErrors as [$field, $code, $message]) {
                $errors[] = self::error($row, $values, $field, $code, $message);
            }
        }

        // The database compares SKUs and location codes without case or
        // accents, and so does the lookup here. Nothing is created from the
        // file, so a match that differs only in spelling is still the one.
        $products = [];
        foreach ($this->products->findBySkus(array_column($read, 'sku')) as $product) {
            $products[Collation::key($product->sku())] = $product;
        }
        /** @var array<string, ?Location> $locations */
        $locations = [];
        foreach ($read as $values) {
            $key = Collation::key($values['location']);
            if (!\array_key_exists($key, $locations)) {
                $locations[$key] = $this->locations->findByCode($values['location']);
            }
        }

        /** @var array<int, array{Product, Location, int}> $valid */
        $valid = [];
        /** @var array<string, int> $seen product id and location id => the row they were first seen on */
        $seen = [];
        foreach ($read as $row => $values) {
            $product = $products[Collation::key($values['sku'])] ?? null;
            $location = $locations[Collation::key($values['location'])] ?? null;
            if (null === $product) {
                $errors[] = self::error($row, $values, 'sku', 'not_found', 'No product has this SKU.');
            }
            if (null === $location) {
                $errors[] = self::error($row, $values, 'location', 'not_found', 'No location has this code.');
            }
            if (null === $product || null === $location) {
                continue;
            }

            $key = $product->id()->toRfc4122()."\0".$location->id()->toRfc4122();
            if (isset($seen[$key])) {
                $errors[] = self::error($row, $values, 'row', 'duplicate', \sprintf('This SKU and location are also on row %d; each can be on one row only.', $seen[$key]));
                continue;
            }
            $seen[$key] = $row;
            $valid[$row] = [$product, $location, $values['quantity']];
        }

        $current = $this->inventory->quantities(array_values(array_unique(array_map(static fn (array $entry): string => $entry[0]->id()->toRfc4122(), $valid))));

        /** @var array<int, array{Product, Location, int}> $changes */
        $changes = [];
        $unchanged = 0;
        foreach ($valid as $row => [$product, $location, $quantity]) {
            $level = $current[$product->id()->toRfc4122()][$location->id()->toRfc4122()] ?? ['onHand' => 0, 'reserved' => 0];
            if ($level['onHand'] === $quantity) {
                ++$unchanged;
            } elseif ($quantity < $level['reserved']) {
                $errors[] = self::error($row, $read[$row], 'quantity', StockRuleViolated::BELOW_RESERVED, \sprintf('On hand cannot go below the %d reserved for orders. Release the reservation first.', $level['reserved']));
            } else {
                $changes[$row] = [$product, $location, $quantity];
            }
        }

        $changed = 0;
        if (!$dryRun) {
            foreach ($changes as $row => [$product, $location, $quantity]) {
                try {
                    if ($this->count($product->id()->toRfc4122(), $location->id()->toRfc4122(), $quantity, $actor)) {
                        ++$changed;
                    } else {
                        ++$unchanged; // someone counted the same in the meantime
                    }
                } catch (StockRuleViolated $violation) {
                    $errors[] = self::error($row, $read[$row], 'quantity', $violation->rule, $violation->getMessage());
                } catch (ConcurrentModification) {
                    $errors[] = self::error($row, $read[$row], 'row', 'conflict', 'This stock was changed by someone else at the same moment. Import the file again.');
                }
                $this->transaction->forget();
            }
        } else {
            $changed = \count($changes);
        }

        usort($errors, static fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return new StockImportResult(
            $dryRun,
            \count($file->rows),
            $changed,
            $unchanged,
            \count(array_unique(array_column($errors, 'row'))),
            $errors,
        );
    }

    /**
     * One row in its own transaction: the level is locked and read fresh, so
     * the check against what is reserved is made on the numbers written.
     * The product and location are loaded again by id inside it: the rows
     * before this one let go of what they read (TransactionInterface::forget()).
     *
     * @return bool whether on hand changed
     */
    private function count(string $productId, string $locationId, int $quantity, Actor $actor): bool
    {
        return $this->transaction->run(function () use ($productId, $locationId, $quantity, $actor): bool {
            $product = $this->products->findById($productId);
            $location = $this->locations->findById($locationId);
            \assert(null !== $product && null !== $location);
            $now = $this->clock->now();

            $level = $this->inventory->lockLevel($product, $location);
            if ($quantity === ($level?->onHand() ?? 0)) {
                return false;
            }
            if (null === $level) {
                $level = new InventoryLevel($product, $location, $now);
                $this->inventory->addLevel($level);
            }

            $change = $level->countAs($quantity, $now);
            $this->inventory->addMovement(InventoryMovement::adjustment($level, $change, AdjustmentReason::Count, null, $actor, $now));

            return true;
        });
    }

    /**
     * One row's values, or null when the row cannot be read.
     *
     * @param list<array{string, string, string}> $errors field, code, message
     *
     * @return array{sku: string, location: string, quantity: int}|null
     */
    private static function values(CsvFile $file, int $row, array &$errors): ?array
    {
        $byField = $file->values($row);
        if (null === $byField) {
            $errors[] = CsvFile::strayCell();

            return null;
        }

        $sku = $byField['sku'] ?? '';
        $location = $byField['location'] ?? '';
        $cell = $byField['quantity'] ?? '';
        if ('' === $sku) {
            $errors[] = ['sku', 'required', 'Every row needs a SKU.'];
        }
        if ('' === $location) {
            $errors[] = ['location', 'required', 'Every row needs a location code.'];
        }
        $quantity = 0;
        if (1 !== preg_match('/^\d{1,10}$/', $cell)) {
            $errors[] = ['quantity', 'integer', 'The quantity is the counted number on hand, a whole number of 0 or more, such as 12.'];
        } elseif (($quantity = (int) $cell) > InventoryLevel::MAX_QUANTITY) {
            $errors[] = ['quantity', StockRuleViolated::TOO_LARGE, \sprintf('On hand cannot exceed %d.', InventoryLevel::MAX_QUANTITY)];
        }

        return ['sku' => $sku, 'location' => $location, 'quantity' => $quantity];
    }

    /**
     * @param array{sku: string, location: string, quantity: int}|null $values
     *
     * @return array{row: int, sku: ?string, location: ?string, field: string, code: string, message: string}
     */
    private static function error(int $row, ?array $values, string $field, string $code, string $message): array
    {
        return [
            'row' => $row,
            'sku' => '' === ($values['sku'] ?? '') ? null : $values['sku'],
            'location' => '' === ($values['location'] ?? '') ? null : $values['location'],
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
