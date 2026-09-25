<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Order;

/**
 * What an order CSV import did, or on a dry run would do. Rows are numbered
 * as a spreadsheet shows them: the header is row 1.
 */
final readonly class OrderImportResult
{
    /**
     * @param list<array{row: int, reference: ?string, field: string, code: string, message: string}> $errors
     */
    public function __construct(
        public bool $dryRun,
        public int $rows,
        /** Distinct order references (per channel) in the file. */
        public int $orders,
        public int $created,
        /** Orders the channel already had under their reference; left alone. */
        public int $existing,
        public int $failed,
        public array $errors,
    ) {
    }
}
