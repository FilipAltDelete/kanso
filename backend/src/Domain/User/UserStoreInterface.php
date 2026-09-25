<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\User;

use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;

interface UserStoreInterface
{
    /** Fields a user list can be sorted by. */
    public const array SORTABLE = ['email', 'name', 'createdAt'];

    public function findById(string $id): ?User;

    /** Ignoring case. */
    public function findByEmail(string $email): ?User;

    /**
     * Every user, deactivated ones included. Search matches any part of the
     * email or name. Filters: `status` (`active` or `deactivated`).
     *
     * @return Page<User>
     */
    public function search(PageRequest $request): Page;

    /**
     * The active admins, read fresh and locked until the surrounding
     * transaction ends, so two admins demoting or deactivating each other at
     * the same time cannot leave none. Must be called inside
     * TransactionInterface::run().
     *
     * @return list<User>
     */
    public function lockActiveAdmins(): array;

    public function save(User $user): void;

    public function count(): int;
}
