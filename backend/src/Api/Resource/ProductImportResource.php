<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody;
use Kanso\Core\Internal\Api\State\ProductImportProcessor;

/**
 * The outcome of a product CSV import (ADR-0006): counts, and every problem
 * found in a row. Rows are numbered as a spreadsheet shows them, the header
 * being row 1.
 */
#[ApiResource(
    shortName: 'ProductImport',
    operations: [
        new Post(
            uriTemplate: '/product-imports',
            status: 200,
            input: false,
            deserialize: false,
            processor: ProductImportProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
            // Every field, every time: `importRunId` is null on a preview, not absent.
            normalizationContext: ['skip_null_values' => false],
            parameters: [
                'dryRun' => new QueryParameter(schema: ['type' => 'boolean'], description: 'Check and count without writing anything: the preview. Default false.'),
                'filename' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 255], description: 'The file\'s name, for the import history (ADR-0014). Optional.'),
            ],
            openapi: new OpenApiOperation(
                summary: 'Create and update products from a CSV file, matched by SKU.',
                description: "The body is the file itself, UTF-8, comma-, semicolon- or tab-separated, with a header row. Columns: `sku` and `name` (required), `barcode` and `weightGrams` (whole grams). A column left out leaves that field unchanged on existing products; an empty cell clears it. At most 5000 rows and 1 MiB.\n\nEvery row is checked first. Rows with problems are listed in `errors` and skipped; the others are written in one transaction. A row equal to the product it matches is not written, so importing the same file again changes nothing. A problem with the file as a whole (encoding, header, size) is a 422 and nothing is written.",
                requestBody: new RequestBody(
                    content: new \ArrayObject(['text/csv' => ['schema' => ['type' => 'string'], 'example' => "sku;name;barcode;weightGrams\nTEE-1;T-shirt;7350000000001;180\n"]]),
                    required: true,
                ),
            ),
        ),
    ],
)]
final class ProductImportResource
{
    /** True when nothing was written. */
    public bool $dryRun = false;
    /** Data rows in the file (the header not counted). */
    public int $rows = 0;
    /** Products created, or on a dry run to be created. */
    public int $created = 0;
    public int $updated = 0;
    /** Rows equal to the product they match. */
    public int $unchanged = 0;
    /** Rows skipped because of the problems in `errors`. */
    public int $failed = 0;
    /** The import's entry in the history (`/api/import-runs/{id}`); null on a dry run, which is not recorded. */
    public ?string $importRunId = null;

    /** @var list<array{row: int, sku: ?string, field: string, code: string, message: string}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'row' => ['type' => 'integer'],
                'sku' => ['type' => ['string', 'null']],
                'field' => ['type' => 'string', 'description' => 'sku, name, barcode, weightGrams, or row for the row as a whole'],
                'code' => ['type' => 'string'],
                'message' => ['type' => 'string'],
            ],
        ],
    ])]
    public array $errors = [];
}
