<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Document;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateDocumentHandler
{
    public function __construct(private readonly DocumentService $documents)
    {
    }

    public function __invoke(GenerateDocument $message): void
    {
        $this->documents->generate($message->documentId);
    }
}
