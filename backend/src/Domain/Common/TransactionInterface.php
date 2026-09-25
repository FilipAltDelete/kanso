<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Common;

/**
 * One database transaction around a unit of work: everything `$work` writes
 * is committed together, or nothing is.
 */
interface TransactionInterface
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws ConcurrentModification when another writer changed the same rows first
     */
    public function run(callable $work): mixed;

    /**
     * Lets go of everything read so far, between the transactions of a long
     * batch (an import writing row by row), so each one costs the same as the
     * first instead of more for every row before it. Entities read before
     * are no longer tracked; read them again by id to change them.
     */
    public function forget(): void;
}
