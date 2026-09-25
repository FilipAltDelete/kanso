<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

interface ChannelStoreInterface
{
    public function findByCode(string $code): ?Channel;

    /** @return list<Channel> by name */
    public function all(): array;
}
