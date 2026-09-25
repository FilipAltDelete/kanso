<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Document;

/** Turns a document template and its data into HTML. */
interface TemplateRendererInterface
{
    /** @param array<string, mixed> $data */
    public function render(DocumentType $type, array $data): string;
}
