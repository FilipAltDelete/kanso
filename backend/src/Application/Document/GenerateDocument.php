<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Document;

/** Render a requested document. Carries only the id: the worker reads the rest fresh. */
final readonly class GenerateDocument
{
    public function __construct(public string $documentId)
    {
    }
}
