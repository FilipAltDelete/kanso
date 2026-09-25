<?php

declare(strict_types=1);

namespace Kanso\Domain\Health;

interface HealthCheckInterface
{
    /**
     * Each dependency by name, mapped to "ok" or a failure description.
     *
     * @return array<string, string>
     */
    public function check(): array;
}
