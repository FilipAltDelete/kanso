<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;

/**
 * `wrapInTransaction` flushes inside the transaction, so a lost optimistic
 * lock or a duplicate key surfaces here, before commit, and rolls everything
 * back. A deadlock or a lock wait that timed out is the same story told by
 * row locks: someone else was writing the same rows.
 *
 * Doctrine closes the entity manager when work inside a transaction fails,
 * since what it holds in memory no longer matches the database. It is reset
 * here, so a request that goes on after a failure — a bulk action moving the
 * next order — gets a fresh one instead of "the EntityManager is closed".
 * Entities loaded before are detached by that; load them again.
 */
final class DoctrineTransaction implements TransactionInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function run(callable $work): mixed
    {
        try {
            return $this->em->wrapInTransaction(static fn (): mixed => $work());
        } catch (\Throwable $e) {
            if (!$this->em->isOpen()) {
                $this->registry->resetManager();
            }
            if ($e instanceof OptimisticLockException || $e instanceof UniqueConstraintViolationException || $e instanceof DeadlockException || $e instanceof LockWaitTimeoutException) {
                throw new ConcurrentModification($e->getMessage(), 0, $e);
            }
            throw $e;
        }
    }
}
