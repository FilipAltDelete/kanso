<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Support;

use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
use Kanso\Core\Internal\Domain\User\User;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;

final class InMemoryUsers implements UserStoreInterface
{
    /** @var array<string, User> */
    public array $items = [];

    /** How often lockActiveAdmins() was called. */
    public int $adminLocks = 0;

    public function findById(string $id): ?User
    {
        return $this->items[$id] ?? null;
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->items as $user) {
            if (mb_strtolower($user->email()) === mb_strtolower($email)) {
                return $user;
            }
        }

        return null;
    }

    public function search(PageRequest $request): Page
    {
        return new Page(array_values($this->items), \count($this->items));
    }

    public function lockActiveAdmins(): array
    {
        ++$this->adminLocks;

        return array_values(array_filter($this->items, static fn (User $user): bool => $user->isEnabled() && $user->isAdmin()));
    }

    public function save(User $user): void
    {
        $this->items[(string) $user->id()] = $user;
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
