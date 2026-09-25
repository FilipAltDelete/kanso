<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\User;

/** The Phase 0 role model; the hierarchy is in config/packages/security.yaml. */
final class Role
{
    public const string ADMIN = 'ROLE_ADMIN';
    public const string OPERATOR = 'ROLE_OPERATOR';
    public const string VIEWER = 'ROLE_VIEWER';

    public const array ALL = [self::ADMIN, self::OPERATOR, self::VIEWER];

    private function __construct()
    {
    }
}
