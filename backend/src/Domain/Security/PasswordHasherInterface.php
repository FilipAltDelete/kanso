<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Security;

interface PasswordHasherInterface
{
    public function hash(string $plainPassword): string;

    public function verify(string $hash, string $plainPassword): bool;

    /**
     * A hash of no one's password, made with the same algorithm and cost as
     * a real one. Signing in verifies against it when the email has no
     * account, so that takes as long as a wrong password does.
     */
    public function decoyHash(): string;
}
