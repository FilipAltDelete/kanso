<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Security;

use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;

interface ApiKeyStoreInterface
{
    /** Fields a key list can be sorted by. */
    public const array SORTABLE = ['name', 'role', 'createdAt', 'expiresAt', 'lastUsedAt'];

    public function findById(string $id): ?ApiKey;

    public function findByHash(string $keyHash): ?ApiKey;

    /**
     * Every key, revoked and expired ones included. Search matches any part
     * of the name.
     *
     * @return Page<ApiKey>
     */
    public function search(PageRequest $request): Page;

    public function save(ApiKey $key): void;
}
