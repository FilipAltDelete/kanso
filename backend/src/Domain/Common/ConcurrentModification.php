<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Common;

/**
 * Someone else wrote the same row between our read and our write: an
 * optimistic lock lost, or a row created twice at once. Nothing was saved.
 */
final class ConcurrentModification extends \RuntimeException
{
}
