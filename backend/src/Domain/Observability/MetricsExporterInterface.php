<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Observability;

/** The read side of the same store: what `/metrics` serves to a scraper. */
interface MetricsExporterInterface
{
    public function render(): string;

    /** The exposition format's content type, version included. */
    public function contentType(): string;
}
