<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Security;

interface ApiKeyStoreInterface
{
    public function findById(string $id): ?ApiKey;

    public function findByHash(string $keyHash): ?ApiKey;

    public function save(ApiKey $key): void;
}
