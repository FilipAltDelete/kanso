<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Security;

interface PasswordHasherInterface
{
    public function hash(string $plainPassword): string;

    public function verify(string $hash, string $plainPassword): bool;
}
