<?php

declare(strict_types=1);

namespace Kanso\Infrastructure\Health;

use Doctrine\DBAL\Connection;
use Kanso\Domain\Health\HealthCheckInterface;

final class HealthChecker implements HealthCheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly \Redis $redis,
    ) {
    }

    public function check(): array
    {
        $checks = [];

        try {
            $this->connection->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'failed: '.$e->getMessage();
        }

        try {
            $checks['redis'] = false !== $this->redis->ping() ? 'ok' : 'failed';
        } catch (\Throwable $e) {
            $checks['redis'] = 'failed: '.$e->getMessage();
        }

        return $checks;
    }
}
