<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Inventory;

/**
 * What a stock CSV import did, or on a dry run would do. Rows are numbered
 * as a spreadsheet shows them: the header is row 1.
 */
final readonly class StockImportResult
{
    /**
     * @param list<array{row: int, sku: ?string, location: ?string, field: string, code: string, message: string}> $errors
     */
    public function __construct(
        public bool $dryRun,
        public int $rows,
        public int $changed,
        public int $unchanged,
        public int $failed,
        public array $errors,
    ) {
    }
}
