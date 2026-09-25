<?php

declare(strict_types=1);

namespace Kanso\Domain\User;

interface UserStoreInterface
{
    public function findById(string $id): ?User;

    public function findByEmail(string $email): ?User;

    public function save(User $user): void;

    public function count(): int;
}
