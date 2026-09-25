<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Import;

use Doctrine\ORM\Mapping as ORM;
use Kanso\Core\Internal\Domain\Common\Actor;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One CSV import that was run for real (ADR-0014): what was imported, by
 * whom, when, from which file, and what came of it — the counts and the rows
 * that failed. Previews (dry runs) are not recorded: they change nothing.
 * Written once, never updated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_run')]
#[ORM\Index(name: 'idx_import_run_type', columns: ['type', 'started_at'])]
class ImportRun
{
    public const string PRODUCTS = 'products';
    public const string ORDERS = 'orders';
    public const array TYPES = [self::PRODUCTS, self::ORDERS];

    /** Rows kept per run; a file with more problems than this is fixed from the first ones anyway. */
    public const int MAX_ERRORS = 1000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename;

    #[ORM\Column(length: 64)]
    private string $actor;

    #[ORM\Column(name: 'actor_name', length: 255)]
    private string $actorName;

    /** @var array<string, int> the import's own counts: rows, created, updated, unchanged, existing, failed… */
    #[ORM\Column(type: 'json')]
    private array $counts;

    #[ORM\Column(name: 'error_count')]
    private int $errorCount;

    /** @var list<array<string, mixed>> the first MAX_ERRORS problems, as the import reported them */
    #[ORM\Column(type: 'json')]
    private array $errors;

    #[ORM\Column(name: 'started_at')]
    private \DateTimeImmutable $startedAt;

    /**
     * @param array<string, int>         $counts
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(string $type, ?string $filename, Actor $actor, array $counts, array $errors, \DateTimeImmutable $startedAt)
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown import type "%s".', $type));
        }

        // v7: time-ordered, so the history sorts by id even within one second.
        $this->id = Uuid::v7();
        $this->type = $type;
        $this->filename = $filename;
        $this->actor = $actor->id;
        $this->actorName = $actor->name;
        $this->counts = $counts;
        $this->errorCount = \count($errors);
        $this->errors = \array_slice($errors, 0, self::MAX_ERRORS);
        $this->startedAt = $startedAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function filename(): ?string
    {
        return $this->filename;
    }

    public function actor(): Actor
    {
        return new Actor($this->actor, $this->actorName);
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** All the problems the import reported, including any not kept. */
    public function errorCount(): int
    {
        return $this->errorCount;
    }

    /** @return list<array<string, mixed>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }
}
