<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody;
use Kanso\Core\Internal\Api\State\OrderImportProcessor;

/**
 * The outcome of an order CSV import (ADR-0008): counts, and every problem
 * found in a row. Rows are numbered as a spreadsheet shows them, the header
 * being row 1.
 */
#[ApiResource(
    shortName: 'OrderImport',
    operations: [
        new Post(
            uriTemplate: '/order-imports',
            status: 200,
            input: false,
            deserialize: false,
            processor: OrderImportProcessor::class,
            security: "is_granted('ROLE_OPERATOR')",
            // Every field, every time: `importRunId` is null on a preview, not absent.
            normalizationContext: ['skip_null_values' => false],
            parameters: [
                'dryRun' => new QueryParameter(schema: ['type' => 'boolean'], description: 'Check and count without writing anything: the preview. Default false.'),
                'filename' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 255], description: 'The file\'s name, for the import history (ADR-0014). Optional.'),
            ],
            openapi: new OpenApiOperation(
                summary: 'Create orders from a CSV file, one row per order line, grouped by order reference.',
                description: "The body is the file itself, UTF-8, comma-, semicolon- or tab-separated, with a header row. Rows with the same `orderReference` (within a channel) are one order. Required columns: `orderReference`, `customerName`, `shippingLine1`, `shippingPostalCode`, `shippingCity`, `shippingCountry`, `sku`, `quantity`, `unitPrice` (major units, such as 199.00). Optional: `channel` (default `manual`), `placedAt`, `currency`, `location`, `customerEmail`, `shippingName`, `shippingLine2`, `shippingRegion`, `shippingPhone`, `lineName`, `paymentStatus` (`unpaid`, `authorized`, `paid`, `refunded`, `partially_refunded`), `tags` (separated by `|`, at most 20), `note` (at most 2000 characters). At most 5000 rows and 1 MiB.\n\nThe reference is stored as the order's `externalReference`, unique per channel: an order the channel already has is left alone, so importing the same file again creates nothing. An order with a `customerEmail` is linked to the customer with that email (ignoring case), and a customer is created from the order's name and email when none has it; `newCustomers` counts those. Every order is checked like one created with `POST /api/orders` and written in its own transaction; an order with any problem is skipped whole and its rows are listed in `errors`. A problem with the file as a whole is a 422 and nothing is written.",
                requestBody: new RequestBody(
                    content: new \ArrayObject(['text/csv' => ['schema' => ['type' => 'string'], 'example' => "orderReference;customerName;shippingLine1;shippingPostalCode;shippingCity;shippingCountry;sku;quantity;unitPrice\nWEB-1001;Anna Andersson;Storgatan 1;111 22;Stockholm;SE;TEE-1;2;199,00\n"]]),
                    required: true,
                ),
            ),
        ),
    ],
)]
final class OrderImportResource
{
    /** True when nothing was written. */
    public bool $dryRun = false;
    /** Data rows in the file (the header not counted). */
    public int $rows = 0;
    /** Distinct order references (per channel) in the file. */
    public int $orders = 0;
    /** Orders created, or on a dry run to be created. */
    public int $created = 0;
    /** Orders the channel already has under their reference; left alone. */
    public int $existing = 0;
    /** Orders skipped because of the problems in `errors`. */
    public int $failed = 0;
    /** Customer records created for a `customerEmail` no customer had, or on a dry run to be created. Every order with an email is linked to the customer that has it. */
    public int $newCustomers = 0;
    /** The import's entry in the history (`/api/import-runs/{id}`); null on a dry run, which is not recorded. */
    public ?string $importRunId = null;

    /** @var list<array{row: int, reference: ?string, field: string, code: string, message: string}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'row' => ['type' => 'integer'],
                'reference' => ['type' => ['string', 'null']],
                'field' => ['type' => 'string', 'description' => 'The column, or row for the row as a whole'],
                'code' => ['type' => 'string'],
                'message' => ['type' => 'string'],
            ],
        ],
    ])]
    public array $errors = [];
}
