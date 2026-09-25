<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Document;

/** Turns a document template and its data into HTML. */
interface TemplateRendererInterface
{
    /**
     * @param array<string, mixed> $data  one order's (OrderDocumentData), or with `batch` a list of them under `orders`
     * @param bool                 $batch several orders in one document
     */
    public function render(DocumentType $type, array $data, bool $batch = false): string;
}
