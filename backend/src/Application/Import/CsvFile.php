<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Import;

use Kanso\Core\Internal\Application\Exception\ValidationFailed;

/**
 * A CSV file as the imports read it (ADR-0006): UTF-8 with or without a
 * byte-order mark, comma, semicolon or tab separated, with a header row whose
 * names are matched ignoring case, spaces, "_" and "-". Rows are numbered as
 * a spreadsheet shows them: the header is row 1, and blank lines count.
 *
 * Problems with the file as a whole are a 422 and nothing is imported:
 * `path` is `file`, or `header.<column>` for a problem with one column.
 */
final class CsvFile
{
    /** The proxy's `client_max_body_size`; a larger body never reaches PHP. */
    public const int MAX_BYTES = 1024 * 1024;
    public const int MAX_ROWS = 5000;

    /**
     * @param array<int, string>            $columns column index => field
     * @param array<int, list<string|null>> $rows    row number => cells
     */
    private function __construct(
        private readonly array $columns,
        public readonly array $rows,
    ) {
    }

    /**
     * @param array<string, string> $fields   field => the header that names it, as documented
     * @param list<string>          $required fields whose column must be present
     */
    public static function read(string $csv, array $fields, array $required): self
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
        $rows = [];
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
            if (self::MAX_ROWS === \count($rows)) {
                fclose($stream);
                self::fileError('too_many_rows', \sprintf('The file has more than %d rows. Split it into smaller files.', self::MAX_ROWS));
            }
            $rows[$row] = $cells;
        }
        fclose($stream);

        if (null === $header) {
            self::fileError('empty', 'The file is empty.');
        }
        if ([] === $rows) {
            self::fileError('no_rows', 'The file has a header but no rows.');
        }

        return new self(self::header($header, $fields, $required), $rows);
    }

    public function has(string $field): bool
    {
        return \in_array($field, $this->columns, true);
    }

    /**
     * One row's trimmed cells by field, for the columns the file has; null
     * when a cell outside the named columns has a value.
     *
     * @return array<string, string>|null
     */
    public function values(int $row): ?array
    {
        $cells = $this->rows[$row];
        // Spreadsheets pad rows with empty cells; only a value outside the named columns is a problem.
        foreach ($cells as $index => $cell) {
            if (!isset($this->columns[$index]) && '' !== trim((string) $cell)) {
                return null;
            }
        }

        $values = [];
        foreach ($this->columns as $index => $field) {
            $values[$field] = trim((string) ($cells[$index] ?? ''));
        }

        return $values;
    }

    /**
     * The error a row gets when values() refuses it.
     *
     * @return array{string, string, string} field, code, message
     */
    public static function strayCell(): array
    {
        return ['row', 'stray_cell', 'A cell has a value but its column has no header.'];
    }

    /**
     * @param list<string|null>     $cells
     * @param array<string, string> $fields
     * @param list<string>          $required
     *
     * @return array<int, string>
     */
    private static function header(array $cells, array $fields, array $required): array
    {
        $byFolded = [];
        foreach ($fields as $field => $name) {
            $byFolded[self::fold($name)] = $field;
        }

        $columns = [];
        $violations = [];
        foreach ($cells as $index => $cell) {
            $name = trim((string) $cell);
            if ('' === $name) {
                continue; // spreadsheet padding; a value under it is caught per row
            }
            $field = $byFolded[self::fold($name)] ?? null;
            if (null === $field) {
                $violations[] = ['path' => 'header.'.$name, 'message' => \sprintf('Unknown column "%s". The columns are %s.', $name, implode(', ', $fields)), 'code' => 'unknown_column'];
            } elseif (\in_array($field, $columns, true)) {
                $violations[] = ['path' => 'header.'.$name, 'message' => \sprintf('The column "%s" appears twice.', $name), 'code' => 'duplicate_column'];
            } else {
                $columns[$index] = $field;
            }
        }
        foreach ($required as $field) {
            if (!\in_array($field, $columns, true)) {
                $violations[] = ['path' => 'header.'.$fields[$field], 'message' => \sprintf('The column "%s" is missing.', $fields[$field]), 'code' => 'missing_column'];
            }
        }
        if ([] !== $violations) {
            throw new ValidationFailed($violations);
        }

        return $columns;
    }

    private static function fold(string $name): string
    {
        return strtolower(str_replace([' ', '_', '-'], '', $name));
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

    private static function fileError(string $code, string $message): never
    {
        throw new ValidationFailed([['path' => 'file', 'message' => $message, 'code' => $code]]);
    }
}
