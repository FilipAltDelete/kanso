<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Document;

/**
 * HTML to PDF. dompdf now, in-process (Pimsen's built-in renderer); Gotenberg
 * can be a second implementation when print fidelity needs a real browser.
 */
interface PdfRendererInterface
{
    /** @return string the PDF's bytes */
    public function render(string $html): string;
}
