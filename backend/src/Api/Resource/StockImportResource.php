<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody;
use Kanso\Core\Internal\Api\State\StockImportProcessor;

/**
 * The outcome of a stock CSV import (ADR-0016): counts, and every problem
 * found in a row. Rows are numbered as a spreadsheet shows them, the header
 * being row 1.
 */
#[ApiResource(
    shortName: 'StockImport',
    operations: [
        new Post(
            uriTemplate: '/stock-imports',
            status: 200,
            input: false,
            deserialize: false,
            processor: StockImportProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
            // Every field, every time: `importRunId` is null on a preview, not absent.
            normalizationContext: ['skip_null_values' => false],
            parameters: [
                'dryRun' => new QueryParameter(schema: ['type' => 'boolean'], description: 'Check and count without writing anything: the preview. Default false.'),
                'filename' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 255], description: 'The file\'s name, for the import history (ADR-0014). Optional.'),
            ],
            openapi: new OpenApiOperation(
                summary: 'Set counted stock quantities from a CSV file.',
                description: "The body is the file itself, UTF-8, comma-, semicolon- or tab-separated, with a header row. Columns, all required: `sku` (an existing product), `location` (an existing location's code) and `quantity` (the counted quantity on hand, a whole number of 0 or more). Each SKU and location pair can be on one row. At most 5000 rows and 1 MiB.\n\nEach row sets on hand to its quantity and is recorded as an adjustment with the reason `count`, in a transaction of its own. A row whose quantity is already on hand is not written, so importing the same file again changes nothing. A quantity below what is reserved for orders is refused. Rows with problems are listed in `errors` and skipped. A problem with the file as a whole (encoding, header, size) is a 422 and nothing is written.",
                requestBody: new RequestBody(
                    content: new \ArrayObject(['text/csv' => ['schema' => ['type' => 'string'], 'example' => "sku;location;quantity\nTEE-1;WH1;120\n"]]),
                    required: true,
                ),
            ),
        ),
    ],
)]
final class StockImportResource
{
    /** True when nothing was written. */
    public bool $dryRun = false;
    /** Data rows in the file (the header not counted). */
    public int $rows = 0;
    /** Levels whose on hand was set, or on a dry run would be. */
    public int $changed = 0;
    /** Rows whose quantity is already on hand. */
    public int $unchanged = 0;
    /** Rows skipped because of the problems in `errors`. */
    public int $failed = 0;
    /** The import's entry in the history (`/api/import-runs/{id}`); null on a dry run, which is not recorded. */
    public ?string $importRunId = null;

    /** @var list<array{row: int, sku: ?string, location: ?string, field: string, code: string, message: string}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'row' => ['type' => 'integer'],
                'sku' => ['type' => ['string', 'null']],
                'location' => ['type' => ['string', 'null']],
                'field' => ['type' => 'string', 'description' => 'sku, location, quantity, or row for the row as a whole'],
                'code' => ['type' => 'string'],
                'message' => ['type' => 'string'],
            ],
        ],
    ])]
    public array $errors = [];
}
