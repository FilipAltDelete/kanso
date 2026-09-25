<?php

declare(strict_types=1);

namespace Kanso\Domain\Security;

use Kanso\Domain\User\User;

interface AccessTokenIssuerInterface
{
    public function issue(User $user): string;

    /** Seconds an access token is valid for. */
    public function ttl(): int;
}
