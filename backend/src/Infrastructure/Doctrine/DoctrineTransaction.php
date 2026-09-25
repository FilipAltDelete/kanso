<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;

/**
 * `wrapInTransaction` flushes inside the transaction, so a lost optimistic
 * lock or a duplicate key surfaces here, before commit, and rolls everything
 * back.
 */
final class DoctrineTransaction implements TransactionInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function run(callable $work): mixed
    {
        try {
            return $this->em->wrapInTransaction(static fn (): mixed => $work());
        } catch (OptimisticLockException|UniqueConstraintViolationException $e) {
            throw new ConcurrentModification($e->getMessage(), 0, $e);
        }
    }
}
