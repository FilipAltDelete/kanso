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
}
