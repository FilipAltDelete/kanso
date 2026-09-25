<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Document;

enum DocumentStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
