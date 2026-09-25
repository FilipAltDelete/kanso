<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Kanso\Core\Internal\Api\State\ImportRunProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A CSV import that was run for real (ADR-0014): who, when, which file, the
 * counts and the rows that failed. Previews are not recorded. The list
 * leaves out the failed rows; one run by id has them.
 */
#[ApiResource(
    shortName: 'ImportRun',
    operations: [
        new GetCollection(
            uriTemplate: '/import-runs',
            provider: ImportRunProvider::class,
            normalizationContext: ['groups' => ['run:list'], 'skip_null_values' => false],
            parameters: [
                'type' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['products', 'orders', 'stock']], description: 'Only imports of this kind.'),
            ],
        ),
        new Get(
            uriTemplate: '/import-runs/{id}',
            provider: ImportRunProvider::class,
            normalizationContext: ['groups' => ['run:list', 'run:detail'], 'skip_null_values' => false],
        ),
    ],
    security: "is_granted('ROLE_VIEWER')",
)]
final class ImportRunResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['run:list'])]
    public string $id = '';

    /** `products`, `orders` or `stock`. */
    #[Groups(['run:list'])]
    public string $type = '';

    /** The file's name as the browser sent it, when it did. */
    #[Groups(['run:list'])]
    public ?string $filename = null;

    #[Groups(['run:list'])]
    public string $actorId = '';

    #[Groups(['run:list'])]
    public string $actorName = '';

    /** @var array<string, int> the import's counts, named as in its result (rows, created, updated, unchanged, orders, existing, changed, failed) */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => ['type' => 'integer']])]
    #[Groups(['run:list'])]
    public array $counts = [];

    /** All the problems the import reported; `errors` keeps the first 1000. */
    #[Groups(['run:list'])]
    public int $errorCount = 0;

    /** @var list<array<string, mixed>> */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['row' => ['type' => 'integer'], 'sku' => ['type' => ['string', 'null']], 'reference' => ['type' => ['string', 'null']], 'location' => ['type' => ['string', 'null']], 'field' => ['type' => 'string'], 'code' => ['type' => 'string'], 'message' => ['type' => 'string']]]])]
    #[Groups(['run:detail'])]
    public array $errors = [];

    #[Groups(['run:list'])]
    public ?\DateTimeImmutable $startedAt = null;
}
